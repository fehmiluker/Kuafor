<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function sub(): Subscription
    {
        return Subscription::where('tenant_id', $this->tenant->id)->latest('id')->first();
    }

    private function signed(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/subscription-payments/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'test-billing-secret')], $body);
    }

    public function test_trial_snapshot_and_expiry(): void
    {
        $this->buildSalon();
        $sub = $this->sub();
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame(3, $sub->entitlements_snapshot['max_branches']);
        $this->assertTrue($sub->entitlements_snapshot['features']['whatsapp']);
        $this->travel(15)->days();
        app(SubscriptionService::class)->sweep();
        $this->assertSame(SubscriptionStatus::Expired, $this->sub()->status);
        $this->assertFalse(app(EntitlementService::class)->isUsable($this->tenant));
    }

    public function test_payment_webhook_is_signed_and_idempotent(): void
    {
        $this->buildSalon();
        $sub = $this->sub();
        $this->call('POST', '/webhooks/subscription-payments/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'bad'], '{}')->assertStatus(401);
        $payload = ['event_id' => 'evt_1', 'subscription_id' => $sub->id, 'status' => 'succeeded', 'amount' => '599.00', 'reference' => 'DEKONT-1'];
        $this->signed($payload)->assertOk();
        $this->signed($payload)->assertOk();
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame('99.83', SubscriptionInvoice::first()->tax_amount->toDecimal(), 'KDV portion of a tax-inclusive 599 TL');
    }

    public function test_renewal_extends_from_period_end_and_lapse_flow(): void
    {
        $this->buildSalon();
        $svc = app(SubscriptionService::class);
        $svc->recordPayment($this->sub(), 'p1');
        $end1 = $this->sub()->current_period_end;
        $svc->recordPayment($this->sub(), 'p2');
        $this->assertTrue($this->sub()->current_period_end->equalTo($end1->addMonth()), 'renewal stacks on the current period');
        $this->travelTo($this->sub()->current_period_end->addHour());
        $svc->sweep();
        $this->assertSame(SubscriptionStatus::PastDue, $this->sub()->status);
        $this->travel(4)->days();
        $svc->sweep();
        $this->assertSame(SubscriptionStatus::GracePeriod, $this->sub()->status);
        $this->travel(8)->days();
        $svc->sweep();
        $this->assertSame(SubscriptionStatus::Suspended, $this->sub()->status);
    }

    public function test_downgrade_is_scheduled_and_data_is_kept(): void
    {
        $this->buildSalon('automatic', 'premium');
        $svc = app(SubscriptionService::class);
        $svc->recordPayment($this->sub(), 'p1');
        $sub = $svc->changePlan($this->sub()->load('plan'), Plan::with('features')->where('slug', 'standart')->first(), BillingInterval::Monthly);
        $this->assertNotNull($sub->pending_plan_id);
        $this->assertSame(10, $sub->entitlements_snapshot['max_branches'], 'still premium until the period ends');
        $svc->recordPayment($sub, 'p2');
        $sub = $this->sub();
        $this->assertSame('standart', $sub->plan->slug);
        $over = app(EntitlementService::class)->overLimit($this->tenant);
        $this->assertSame(0, $over['branches']);
        $this->assertSame(0, $over['employees']); // exactly 2 employees, standart allows 2
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_plan_edit_does_not_change_existing_snapshot(): void
    {
        $this->buildSalon();
        Plan::where('slug', 'profesyonel')->update(['max_branches' => 1]);
        $this->assertSame(3, $this->sub()->entitlements_snapshot['max_branches']);
    }

    public function test_feature_gating_and_suspended_tenant(): void
    {
        $this->buildSalon('automatic', 'standart');
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/whatsapp/connections')->assertForbidden()->assertJsonPath('errors.0.code', 'feature_unavailable');
        app(SubscriptionService::class)->setStatus($this->sub(), SubscriptionStatus::Suspended);
        $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/appointments')->assertOk(); // read-only access remains
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/customers', ['full_name' => 'A B', 'phone' => '05551112233'])->assertStatus(402);
        // Public: suspended tenants are hidden and refuse bookings.
        $this->getJson('/api/v1/salons/'.$this->tenant->slug)->assertNotFound();
        $this->expectException(DomainRuleException::class);
        $this->book($this->at('10:00'));
    }

    public function test_admin_manual_activation(): void
    {
        $this->buildSalon();
        $admin = $this->superAdmin();
        $token = $admin->createToken('t', ['*', 'platform'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/admin/subscriptions/'.$this->sub()->id, ['record_payment' => true, 'payment_reference' => 'HAVALE-42', 'reason' => 'Havale alındı'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->patchJson('/api/v1/admin/subscriptions/'.$this->sub()->id, ['record_payment' => true, 'payment_reference' => 'HAVALE-42', 'reason' => 'Tekrar'])->assertOk();
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.subscription_updated', 'reason' => 'Havale alındı']);
    }

    public function test_customers_are_never_asked_to_pay_online(): void
    {
        $this->buildSalon('automatic');
        $r = $this->postJson('/api/v1/appointments', ['branch_id' => $this->branch->id, 'service_ids' => [$this->cut->id], 'date' => '2026-11-02', 'time' => '10:00',
            'full_name' => 'Ali Veli', 'phone' => '05551112233', 'kvkk_acknowledged' => true])->assertCreated();
        $this->assertFalse($r->json('meta.online_payment_required'));
        $this->assertSame('Ödeme salonda yapılır.', $r->json('data.payment_note'));
        $this->get('/salon/'.$this->tenant->slug.'/randevu')->assertOk()->assertSee('platform üzerinden ödeme alınmaz')->assertDontSee('kart numarası');
        $this->assertStringContainsString('Online ödeme veya kapora alınmaz', file_get_contents(resource_path('views/livewire/public/booking-wizard.blade.php')));
    }
}
