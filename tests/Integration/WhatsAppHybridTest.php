<?php

namespace Tests\Integration;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\TenantRole;
use App\Models\Appointment;
use App\Models\WhatsAppConnection;
use App\Services\Booking\PublicBookingService;
use App\Services\Settings\SettingsRepository;
use App\Services\WhatsApp\ConnectionService;
use App\Services\WhatsApp\FakeWhatsAppClient;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Support\PhoneNumber;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

/**
 * Hybrid model: platform number by default, optional own number per branch
 * (Embedded Signup or admin-assisted).
 */
class WhatsAppHybridTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private const PLATFORM = '+908501112233';

    private const MANAGER = '+905321110002';

    private const CUSTOMER = '+905551112233';

    private FakeWhatsAppClient $wa;

    private WhatsAppConnection $platform;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->wa = app(WhatsAppClientInterface::class);
        $this->platform = app(ConnectionService::class)->connectPlatform('100000000000099', '200000000000099', 'EAAG-platform-token-123456', self::PLATFORM);
    }

    private function salon(string $approval = 'manual', string $plan = 'profesyonel'): void
    {
        $this->buildSalon($approval, $plan);
        app(SettingsRepository::class)->setBranch($this->branch, ['whatsapp_manager_numbers' => [self::MANAGER]]);
    }

    private function book(string $time = '10:00', string $phone = self::CUSTOMER): Appointment
    {
        return app(PublicBookingService::class)->book($this->branch, [
            'service_ids' => [$this->cut->id], 'employee_id' => $this->ayse->id, 'date' => '2026-11-02', 'time' => $time,
            'full_name' => 'Zeynep Kaya', 'phone' => $phone, 'whatsapp_opt_in' => true,
        ], null, AppointmentSource::Web)['appointment'];
    }

    private function tap(string $phoneNumberId, string $from, string $payload): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'messages' => [['from' => PhoneNumber::toWaId($from), 'id' => 'wamid.'.bin2hex(random_bytes(6)), 'type' => 'button', 'button' => ['payload' => $payload]]],
        ]]]]]]);
        $this->call('POST', '/webhooks/meta/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret')], $body)->assertOk();
    }

    public function test_salon_without_own_number_sends_via_platform_with_salon_name(): void
    {
        $this->salon();
        $this->book();
        $toCustomer = $this->wa->sentTo(self::CUSTOMER);
        $this->assertNotEmpty($toCustomer);
        $this->assertSame($this->platform->id, $toCustomer[0]['connection_id']);
        $this->assertStringContainsString('Işıltı Kuaför', json_encode($toCustomer[0]['payload'], JSON_UNESCAPED_UNICODE));
        $toSalon = collect($this->wa->sentTo(self::MANAGER))->firstWhere('payload.name', 'salon_yeni_talep');
        $this->assertStringContainsString('Işıltı Kuaför – Merkez', json_encode($toSalon['payload'], JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('whatsapp_messages', ['connection_id' => $this->platform->id, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
    }

    public function test_salon_approves_via_platform_number_and_tenant_is_resolved_from_token(): void
    {
        $this->salon();
        $a = $this->book();
        [$approve] = FakeWhatsAppClient::actionPayloads(collect($this->wa->sentTo(self::MANAGER))->firstWhere('payload.name', 'salon_yeni_talep'));
        $this->tap('200000000000099', self::MANAGER, $approve);
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertDatabaseHas('whatsapp_messages', ['connection_id' => $this->platform->id, 'direction' => 'inbound', 'tenant_id' => $this->tenant->id]);
    }

    public function test_platform_number_keeps_tenants_apart(): void
    {
        $this->salon();
        $a = $this->book();
        $other = SalonBuilder::make()->tenant('Diğer Salon', $this->plan());
        $ob = $other->branch('Şube');
        $os = $other->service('Kesim', 30, '100.00', [$ob]);
        $other->employee('Ali', [$ob], [$os]);
        app(SettingsRepository::class)->setBranch($ob, ['whatsapp_manager_numbers' => ['+905329998877']]);
        app(PublicBookingService::class)->book($ob->setRelation('tenant', $other->get()), ['service_ids' => [$os->id], 'date' => '2026-11-02', 'time' => '10:00',
            'full_name' => 'Ali Veli', 'phone' => '+905551110000', 'whatsapp_opt_in' => true], null, AppointmentSource::Web);

        // Salon B's manager taps salon A's button (forwarded message): sender check rejects it.
        [$approveA] = FakeWhatsAppClient::actionPayloads(collect($this->wa->sentTo(self::MANAGER))->firstWhere('payload.name', 'salon_yeni_talep'));
        $this->tap('200000000000099', '+905329998877', $approveA);
        $this->assertSame(AppointmentStatus::Pending, $a->fresh()->status);
        // Unknown / garbage payload on the platform number is recorded without a tenant and answered generically.
        $this->tap('200000000000099', '+905550000000', 'a:'.str_repeat('x', 40));
        $this->assertDatabaseHas('whatsapp_messages', ['connection_id' => $this->platform->id, 'direction' => 'inbound', 'tenant_id' => null, 'recipient_e164' => '+905550000000']);
    }

    public function test_token_sent_over_one_number_cannot_be_used_on_another(): void
    {
        $this->salon();
        $a = $this->book();
        [$approve] = FakeWhatsAppClient::actionPayloads(collect($this->wa->sentTo(self::MANAGER))->firstWhere('payload.name', 'salon_yeni_talep'));
        // The salon now connects its own number; the old platform-issued button must not work through it.
        app(ConnectionService::class)->connectViaEmbeddedSignup($this->branch, 'code-1', '100000000000001', '200000000000001');
        $this->tap('200000000000001', self::MANAGER, $approve);
        $this->assertSame(AppointmentStatus::Pending, $a->fresh()->status);
        // ...but still works on the platform number it was sent from.
        $this->tap('200000000000099', self::MANAGER, $approve);
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
    }

    public function test_embedded_signup_switches_branch_to_its_own_number(): void
    {
        $this->salon();
        $owner = $this->owner($this->tenant);
        $this->actingAs($owner)->get('/panel/subeler/'.$this->branch->id.'/whatsapp')->assertOk()->assertSee('Platform numarası')->assertSee("WhatsApp'ı bağla", false);
        $this->actingAs($owner)->postJson('/panel/subeler/'.$this->branch->id.'/whatsapp/embedded', ['code' => 'abc', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001'])
            ->assertOk()->assertJsonPath('ok', true);
        $c = WhatsAppConnection::withoutGlobalScopes()->where('branch_id', $this->branch->id)->first();
        $this->assertSame('embedded_signup', $c->onboarding_method);
        $this->assertSame('connected', $c->status->value);
        $this->assertNotNull($c->encrypted_pin);
        $this->assertStringStartsWith('sandbox-es-token-', $c->encrypted_credential_reference);
        $this->book();
        $this->assertSame($c->id, $this->wa->sentTo(self::CUSTOMER)[0]['connection_id'], 'own number now preferred');
        $this->actingAs($owner)->get('/panel/subeler/'.$this->branch->id.'/whatsapp')->assertSee('Kendi numaranız');
    }

    public function test_embedded_signup_api_and_failure(): void
    {
        $this->salon();
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/branches/{$this->branch->id}/whatsapp/embedded-signup", ['code' => 'invalid', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'embedded_signup_failed');
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/branches/{$this->branch->id}/whatsapp/embedded-signup", ['code' => 'ok', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000099'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'number_in_use'); // platform's number
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/branches/{$this->branch->id}/whatsapp/embedded-signup", ['code' => 'ok', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001'])
            ->assertOk()->assertJsonPath('data.onboarding_method', 'embedded_signup');
        $this->api($owner, $this->tenant)->getJson("/api/v1/tenant/branches/{$this->branch->id}/whatsapp/status")->assertJsonPath('data.sends_via', 'own');
        $this->assertStringNotContainsString('sandbox-es-token', $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/whatsapp/connections')->getContent());
    }

    public function test_receptionist_cannot_connect_numbers(): void
    {
        $this->salon();
        $rec = $this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]);
        $this->actingAs($rec)->postJson('/panel/subeler/'.$this->branch->id.'/whatsapp/embedded', ['code' => 'abc', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001'])->assertForbidden();
    }

    public function test_plan_without_whatsapp_gets_no_platform_messages(): void
    {
        $this->salon('automatic', 'standart');
        $this->book();
        $this->assertSame([], $this->wa->sent);
    }

    public function test_platform_fallback_can_be_disabled_and_platform_disconnected(): void
    {
        $this->salon('automatic');
        config(['whatsapp.platform_fallback' => false]);
        $this->book('10:00');
        $this->assertSame([], $this->wa->sent);
        config(['whatsapp.platform_fallback' => true]);
        app(ConnectionService::class)->disconnect($this->platform, 'admin');
        $this->book('12:00', '+905551112299');
        $this->assertSame([], $this->wa->sent);
    }

    public function test_admin_manages_platform_number_and_assisted_branch_setup(): void
    {
        $this->salon();
        $this->asAdminWeb()->get('/admin/sistem')->assertOk()->assertSee('Platform WhatsApp numarası')->assertSee(self::PLATFORM);
        $this->post('/admin/isletmeler/'.$this->tenant->id.'/subeler/'.$this->branch->id.'/whatsapp', [
            'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001', 'access_token' => 'EAAG-assisted-token-123456', 'reason' => 'Destek talebi #42',
        ])->assertRedirect();
        $this->assertDatabaseHas('whatsapp_connections', ['branch_id' => $this->branch->id, 'onboarding_method' => 'admin', 'status' => 'connected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.branch_whatsapp_connected', 'reason' => 'Destek talebi #42', 'tenant_id' => $this->tenant->id]);
        $this->get('/admin/isletmeler/'.$this->tenant->id)->assertOk()->assertSee('Kendi numarası');
        $this->delete('/admin/sistem/whatsapp')->assertRedirect();
        $this->assertSame('disconnected', $this->platform->fresh()->status->value);
    }

    public function test_tenant_owner_cannot_manage_platform_number(): void
    {
        $this->salon();
        $this->actingAs($this->owner($this->tenant))->post('/admin/sistem/whatsapp', ['waba_id' => '1', 'phone_number_id' => '1', 'access_token' => 'x'])->assertForbidden();
    }
}
