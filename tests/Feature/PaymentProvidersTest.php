<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

/** PayTR subscription checkout, with the provider's HTTP API faked. */
class PaymentProvidersTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private const KEY = 'test-merchant-key';

    private const SALT = 'test-merchant-salt';

    private function usePaytr(): void
    {
        config([
            'subscriptions.provider' => 'paytr',
            'subscriptions.paytr.merchant_id' => '123456',
            'subscriptions.paytr.merchant_key' => self::KEY,
            'subscriptions.paytr.merchant_salt' => self::SALT,
            'subscriptions.paytr.test_mode' => true,
        ]);
        $this->app->forgetInstance(PaymentGatewayInterface::class);
    }

    private function sub(): Subscription
    {
        return Subscription::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
    }

    private function startCheckout(): SubscriptionCheckout
    {
        Http::fake(['www.paytr.com/odeme/api/get-token' => Http::response(['status' => 'success', 'token' => 'tok123'])]);
        $this->actingAs($this->owner($this->tenant));
        $this->post('/panel/abonelik/odeme')->assertRedirect();

        return SubscriptionCheckout::latest('id')->firstOrFail();
    }

    private function paytrCallback(SubscriptionCheckout $c, string $status = 'success', ?int $total = null, ?string $hash = null): TestResponse
    {
        $total ??= $c->amount->minor;
        $hash ??= base64_encode(hash_hmac('sha256', $c->merchant_oid.self::SALT.$status.$total, self::KEY, true));

        return $this->post('/webhooks/subscription-payments/paytr', [
            'merchant_oid' => $c->merchant_oid, 'status' => $status, 'total_amount' => (string) $total, 'hash' => $hash,
            'failed_reason_code' => $status === 'success' ? '' : '2', 'failed_reason_msg' => $status === 'success' ? '' : 'Yetersiz bakiye',
            'test_mode' => '1', 'payment_type' => 'card', 'currency' => 'TL', 'payment_amount' => (string) $total,
        ]);
    }

    public function test_paytr_checkout_sends_signed_token_request_and_embeds_iframe(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        $checkout = $this->startCheckout();

        $this->assertSame('pending', $checkout->status);
        $this->assertTrue($checkout->amount->equals($this->sub()->price_snapshot));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $checkout->merchant_oid, 'PayTR merchant_oid must be alphanumeric');

        Http::assertSent(function (HttpRequest $r) use ($checkout) {
            $d = $r->data();
            $hashStr = '123456'.$d['user_ip'].$checkout->merchant_oid.$d['email'].$d['payment_amount']
                .$d['user_basket'].$d['no_installment'].$d['max_installment'].$d['currency'].$d['test_mode'];
            $basket = json_decode(base64_decode($d['user_basket']), true);

            return $r->url() === 'https://www.paytr.com/odeme/api/get-token'
                && $d['paytr_token'] === base64_encode(hash_hmac('sha256', $hashStr.self::SALT, self::KEY, true))
                && $d['payment_amount'] === (string) $checkout->amount->minor
                && $basket[0][1] === $checkout->amount->toDecimal() && $basket[0][2] === 1
                && $d['currency'] === 'TL' && $d['test_mode'] === '1' && $d['no_installment'] === '1'
                && str_contains($d['merchant_ok_url'], '/panel/abonelik');
        });

        $page = $this->get('/panel/abonelik/odeme/'.$checkout->merchant_oid)->assertOk()
            ->assertSee('https://www.paytr.com/odeme/guvenli/tok123', false);
        $this->assertStringContainsString('frame-src https://*.facebook.com https://www.paytr.com', $page->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('paytr', $this->get('/panel/abonelik')->assertOk()->assertSee('Kartla öde')->headers->get('Content-Security-Policy'));
    }

    public function test_paytr_callback_is_verified_activates_once_and_answers_ok(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        $checkout = $this->startCheckout();

        $this->paytrCallback($checkout, hash: 'forged')->assertStatus(401);
        $this->assertSame(SubscriptionStatus::Trialing, $this->sub()->status);

        $this->paytrCallback($checkout)->assertOk()->assertSeeText('OK');
        $this->paytrCallback($checkout)->assertOk()->assertSeeText('OK'); // PayTR retries until it gets OK

        $sub = $this->sub();
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseHas('subscription_payments', ['provider' => 'paytr', 'provider_event_id' => $checkout->merchant_oid, 'status' => 'succeeded']);
        $this->assertSame('succeeded', $checkout->fresh()->status);

        // A completed checkout can no longer be opened.
        $this->get('/panel/abonelik/odeme/'.$checkout->merchant_oid)->assertRedirect('/panel/abonelik');
    }

    public function test_paytr_failed_or_underpaid_callbacks_do_not_activate(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        $failed = $this->startCheckout();
        $this->paytrCallback($failed, 'failed')->assertOk()->assertSeeText('OK');
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertStringContainsString('Yetersiz bakiye', $failed->fresh()->failure_reason);

        $under = $this->startCheckout();
        $this->paytrCallback($under, total: $under->amount->minor - 100)->assertOk();
        $this->assertSame('amount_mismatch', $under->fresh()->failure_reason);

        $this->assertSame(SubscriptionStatus::Trialing, $this->sub()->status);
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_only_subscription_managers_can_start_card_payment(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        Http::fake();
        $this->actingAs($this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]));
        $this->post('/panel/abonelik/odeme')->assertForbidden();
        $this->assertDatabaseCount('subscription_checkouts', 0);
        Http::assertNothingSent();
    }

    public function test_paytr_refusal_is_shown_without_leaking_details(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        Http::fake(['www.paytr.com/*' => Http::response(['status' => 'failed', 'reason' => 'paytr_token gecersiz'])]);
        $this->actingAs($this->owner($this->tenant));
        $this->from('/panel/abonelik')->post('/panel/abonelik/odeme')
            ->assertDontSee('paytr_token gecersiz');
        $this->assertNull(SubscriptionCheckout::first()->provider_token);
        $this->assertSame('failed', SubscriptionCheckout::first()->status);
    }

    public function test_manual_provider_shows_no_card_payment(): void
    {
        $this->buildSalon();
        $this->actingAs($this->owner($this->tenant));
        $this->get('/panel/abonelik')->assertOk()->assertDontSee('Kartla öde')->assertSee('manuel aktivasyon');
        $this->post('/panel/abonelik/odeme')->assertNotFound();
    }

    public function test_provider_network_errors_become_friendly_messages(): void
    {
        $this->usePaytr();
        $this->buildSalon();
        Http::fake(['www.paytr.com/*' => Http::response('Forbidden', 403)]);
        $this->actingAs($this->owner($this->tenant));
        $this->from('/panel/abonelik')->post('/panel/abonelik/odeme')->assertRedirect('/panel/abonelik');
        $this->assertSame('failed', SubscriptionCheckout::first()->status);
    }
}
