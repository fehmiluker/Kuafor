<?php

namespace Tests\Integration;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminders;
use App\Jobs\SendWhatsAppMessage;
use App\Mail\AppointmentNotificationMail;
use App\Models\Appointment;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PublicBookingService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Settings\SettingsRepository;
use App\Services\Tenancy\TenantContext;
use App\Services\WhatsApp\ConnectionService;
use App\Services\WhatsApp\FakeWhatsAppClient;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppSender;
use App\Support\PhoneNumber;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class WhatsAppFlowTest extends TestCase
{
    use BuildsSalon;

    private const SENDER = '+902161112233';

    private const MANAGER = '+905321110002';

    private const CUSTOMER = '+905551112233';

    private FakeWhatsAppClient $wa;

    private WhatsAppConnection $connection;

    private function setUpWhatsApp(string $approval = 'manual', string $plan = 'profesyonel', array $managers = [self::MANAGER]): void
    {
        Mail::fake();
        $this->buildSalon($approval, $plan);
        $this->wa = app(WhatsAppClientInterface::class);
        if ($plan !== 'standart') {
            $this->connection = app(ConnectionService::class)->connect($this->branch, '100000000000001', '200000000000001', 'EAAG-test-token-123456', self::SENDER);
        }
        app(SettingsRepository::class)->setBranch($this->branch, ['whatsapp_manager_numbers' => $managers]);
    }

    private function bookViaWeb(string $time = '10:00', bool $optIn = true, ?string $email = null): Appointment
    {
        return app(PublicBookingService::class)->book($this->branch, [
            'service_ids' => [$this->cut->id], 'employee_id' => $this->ayse->id, 'date' => '2026-11-02', 'time' => $time,
            'full_name' => 'Zeynep Kaya', 'phone' => self::CUSTOMER, 'email' => $email, 'whatsapp_opt_in' => $optIn,
        ], null, AppointmentSource::Web)['appointment'];
    }

    private function webhook(array $value, ?string $secret = 'test-app-secret'): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '100000000000001', 'changes' => [['field' => 'messages', 'value' => $value + [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '902161112233', 'phone_number_id' => '200000000000001'],
        ]]]]]]);
        $sig = $secret ? 'sha256='.hash_hmac('sha256', $body, $secret) : null;

        return $this->call('POST', '/webhooks/meta/whatsapp', [], [], [], array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig]), $body);
    }

    private function tap(string $fromE164, string $payload, string $kind = 'button', ?string $wamid = null): TestResponse
    {
        $msg = ['from' => PhoneNumber::toWaId($fromE164), 'id' => $wamid ?? 'wamid.IN'.bin2hex(random_bytes(6)), 'timestamp' => (string) time()];
        $msg += match ($kind) {
            'button' => ['type' => 'button', 'button' => ['payload' => $payload, 'text' => 'x']],
            'list' => ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $payload, 'title' => 'x']]],
        };

        return $this->webhook(['contacts' => [['wa_id' => PhoneNumber::toWaId($fromE164)]], 'messages' => [$msg]]);
    }

    private function lastTo(string $e164): array
    {
        $msgs = $this->wa->sentTo($e164);
        $this->assertNotEmpty($msgs, "no message to {$e164}");

        return end($msgs);
    }

    public function test_get_verification_challenge(): void
    {
        $this->get('/webhooks/meta/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/meta/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
    }

    public function test_post_signature_is_required_on_raw_body(): void
    {
        $this->setUpWhatsApp();
        $this->webhook(['statuses' => []], null)->assertStatus(401);
        $this->webhook(['statuses' => []], 'forged-secret')->assertStatus(401);
        $this->webhook(['statuses' => []])->assertOk();
    }

    public function test_full_salon_approval_via_whatsapp_button(): void
    {
        $this->setUpWhatsApp();
        $a = $this->bookViaWeb();
        $this->assertSame(AppointmentStatus::Pending, $a->status);

        $customerMsg = $this->lastTo(self::CUSTOMER);
        $this->assertSame('randevu_talebi_alindi', $customerMsg['payload']['name']);
        $this->assertSame($this->connection->id, $customerMsg['connection_id'], 'sent from the branch number');

        $salonMsg = $this->lastTo(self::MANAGER);
        $this->assertSame('salon_yeni_talep', $salonMsg['payload']['name']);
        [$approve, $propose] = FakeWhatsAppClient::actionPayloads($salonMsg);
        $this->assertStringNotContainsString(self::CUSTOMER, json_encode($salonMsg['payload']['components'][1]), 'no PII in button payloads');
        $this->assertDatabaseHas('panel_notifications', ['appointment_id' => $a->id, 'event_type' => 'appointment_requested']);
        Mail::assertQueued(AppointmentNotificationMail::class); // signed panel link for staff as well

        $this->tap(self::MANAGER, $approve)->assertOk()->assertSee('EVENT_RECEIVED');
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertSame('randevu_onaylandi', $this->lastTo(self::CUSTOMER)['payload']['name']);
        $this->assertSame('text', $this->lastTo(self::MANAGER)['type'], 'confirmation reply inside the 24h window');
        // The other button is now dead.
        $count = count($this->wa->sent);
        $this->tap(self::MANAGER, $propose)->assertOk();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertSame('text', $this->lastTo(self::MANAGER)['type']);
        $this->assertMatchesRegularExpression('/geçersiz|zaten/u', $this->lastTo(self::MANAGER)['payload']['body']);
        $this->assertGreaterThan($count, count($this->wa->sent));
    }

    public function test_alternative_time_via_list_then_customer_accepts_with_button(): void
    {
        $this->setUpWhatsApp();
        $a = $this->bookViaWeb();
        [, $propose] = FakeWhatsAppClient::actionPayloads($this->lastTo(self::MANAGER));

        $this->tap(self::MANAGER, $propose)->assertOk();
        $list = $this->lastTo(self::MANAGER);
        $this->assertSame('interactive', $list['type']);
        $this->assertSame('list', $list['payload']['type']);
        $rows = $list['payload']['action']['sections'][0]['rows'];
        $this->assertNotEmpty($rows);
        $this->assertLessThanOrEqual(10, count($rows));
        $pick = $rows[3];

        $this->tap(self::MANAGER, $pick['id'], 'list')->assertOk();
        $a->refresh();
        $this->assertSame(AppointmentStatus::AlternativeProposed, $a->status);
        $offer = $this->lastTo(self::CUSTOMER);
        $this->assertSame('randevu_alternatif_teklif', $offer['payload']['name']);
        [$accept, $reject] = FakeWhatsAppClient::actionPayloads($offer);

        // Someone else's number cannot accept for the customer.
        $this->tap('+905559998877', $accept)->assertOk();
        $this->assertSame(AppointmentStatus::AlternativeProposed, $a->fresh()->status);

        $this->tap(self::CUSTOMER, $accept)->assertOk();
        $a->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame('salon_randevu_guncelleme', $this->lastTo(self::MANAGER)['payload']['name']);
        $this->tap(self::CUSTOMER, $reject)->assertOk();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status, 'sibling token invalidated');
    }

    public function test_customer_rejects_proposal_via_button(): void
    {
        $this->setUpWhatsApp();
        $a = $this->bookViaWeb();
        app(AppointmentService::class)->propose($a, $this->at('15:00'), null, Actor::system('t'));
        [, $reject] = FakeWhatsAppClient::actionPayloads($this->lastTo(self::CUSTOMER));
        $this->tap(self::CUSTOMER, $reject)->assertOk();
        $this->assertSame(AppointmentStatus::Rejected, $a->fresh()->status);
    }

    public function test_duplicate_webhook_delivery_is_processed_once(): void
    {
        $this->setUpWhatsApp();
        $a = $this->bookViaWeb();
        [$approve] = FakeWhatsAppClient::actionPayloads($this->lastTo(self::MANAGER));
        $this->tap(self::MANAGER, $approve, wamid: 'wamid.SAME')->assertOk();
        $histories = DB::table('appointment_status_histories')->where('appointment_id', $a->id)->count();
        $sent = count($this->wa->sent);
        $this->tap(self::MANAGER, $approve, wamid: 'wamid.SAME')->assertOk();
        $this->assertSame($histories, DB::table('appointment_status_histories')->where('appointment_id', $a->id)->count());
        $this->assertSame($sent, count($this->wa->sent), 'no reply to a duplicate');
        $this->assertSame(1, DB::table('whatsapp_webhook_events')->where('provider_event_key', 'msg:wamid.SAME')->count());
    }

    public function test_delivery_statuses_are_monotonic(): void
    {
        $this->setUpWhatsApp();
        $this->bookViaWeb();
        $msg = WhatsAppMessage::withoutGlobalScopes()->where('recipient_e164', self::CUSTOMER)->firstOrFail();
        $status = fn ($s, $ts) => $this->webhook(['statuses' => [['id' => $msg->provider_message_id, 'status' => $s, 'timestamp' => (string) $ts, 'recipient_id' => '905551112233']]]);
        $status('delivered', time())->assertOk();
        $status('read', time() + 5)->assertOk();
        $status('delivered', time() + 1)->assertOk(); // late/out-of-order
        $msg->refresh();
        $this->assertSame('read', $msg->status->value);
        $this->assertNotNull($msg->delivered_at);
        $this->assertNotNull($msg->read_at);
    }

    public function test_tokens_cannot_cross_tenants(): void
    {
        $this->setUpWhatsApp();
        $this->bookViaWeb();
        [$approve] = FakeWhatsAppClient::actionPayloads($this->lastTo(self::MANAGER));
        // Same token delivered to ANOTHER tenant's connection must be refused.
        $b = SalonBuilder::make()->tenant('Diğer', $this->plan());
        $otherBranch = $b->branch('Diğer Şube');
        $other = WhatsAppConnection::withoutGlobalScopes()->create(['tenant_id' => $b->get()->id, 'branch_id' => $otherBranch->id, 'phone_number_id' => '999', 'display_phone' => '+902120000000', 'encrypted_credential_reference' => 'x-token-abcdefghijklmnop', 'status' => 'connected']);
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '999'], 'messages' => [['from' => '905321110002', 'id' => 'wamid.X1', 'type' => 'button', 'button' => ['payload' => $approve]]]]]]]]]);
        $this->call('POST', '/webhooks/meta/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret')], $body)->assertOk();
        $this->assertSame('pending', DB::table('appointments')->value('status'));
        $this->assertNotNull($other);
    }

    public function test_manager_number_equal_to_sender_is_skipped_and_panel_used(): void
    {
        $this->setUpWhatsApp('manual', 'profesyonel', [self::SENDER]);
        $a = $this->bookViaWeb();
        $this->assertSame([], $this->wa->sentTo(self::SENDER));
        $this->assertDatabaseHas('panel_notifications', ['appointment_id' => $a->id]);
        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => str_contains((string) $m->actionUrl, 'signature='));
    }

    public function test_plan_without_whatsapp_falls_back_to_email(): void
    {
        $this->setUpWhatsApp('automatic', 'standart');
        $this->bookViaWeb(email: 'zeynep@example.com');
        $this->assertSame([], $this->wa->sent);
        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => $m->hasTo('zeynep@example.com'));
    }

    public function test_no_opt_in_means_no_whatsapp_to_customer(): void
    {
        $this->setUpWhatsApp('automatic');
        $this->bookViaWeb(optIn: false, email: 'z@example.com');
        $this->assertSame([], $this->wa->sentTo(self::CUSTOMER));
        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => $m->hasTo('z@example.com'));
    }

    public function test_unapproved_template_falls_back(): void
    {
        $this->setUpWhatsApp('automatic');
        DB::table('whatsapp_templates')->where('key', 'appointment_confirmed')->update(['approval_status' => 'PENDING']);
        $this->bookViaWeb(email: 'z@example.com');
        $this->assertSame([], $this->wa->sentTo(self::CUSTOMER));
        Mail::assertQueued(AppointmentNotificationMail::class);
    }

    public function test_invalid_token_marks_connection_error_and_falls_back(): void
    {
        $this->setUpWhatsApp('automatic');
        $this->wa->failNext[] = new WhatsAppException('Invalid OAuth access token.', '190', 401);
        $this->bookViaWeb(email: 'z@example.com');
        $this->assertSame('error', $this->connection->fresh()->status->value);
        // First send (salon notification) fails with 190 -> connection disabled -> customer gets e-mail instead.
        $this->assertDatabaseHas('whatsapp_messages', ['recipient_e164' => self::MANAGER, 'status' => 'failed', 'error_code' => '190']);
        $this->assertSame([], $this->wa->sentTo(self::CUSTOMER));
        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => $m->hasTo('z@example.com'));
    }

    public function test_rate_limit_or_timeout_is_retried_not_failed(): void
    {
        $this->setUpWhatsApp('automatic');
        $this->wa->failNext[] = new WhatsAppException('Rate limit hit', '130429', 429);
        $this->bookViaWeb();
        $row = WhatsAppMessage::withoutGlobalScopes()->where('recipient_e164', self::MANAGER)->firstOrFail();
        $this->assertSame('queued', $row->status->value, 'released for retry with backoff');
        $this->assertSame('130429', $row->error_code);
        // Next attempt succeeds and never double-sends.
        (new SendWhatsAppMessage($row->id, ['kind' => 'text', 'body' => 'x']))->handle($this->wa);
        (new SendWhatsAppMessage($row->id, ['kind' => 'text', 'body' => 'x']))->handle($this->wa);
        $this->assertSame('sent', $row->fresh()->status->value);
        $this->assertCount(1, $this->wa->sentTo(self::MANAGER), 'exactly one delivery despite two job runs');
        // Re-running the whole notification for the same state does not create a second message.
        app(NotificationDispatcher::class)->appointmentCreated(Appointment::withoutGlobalScopes()->first());
        $this->assertSame(1, WhatsAppMessage::withoutGlobalScopes()->where('recipient_e164', self::MANAGER)->count());
    }

    public function test_disconnected_connection_stops_sending(): void
    {
        $this->setUpWhatsApp('automatic');
        app(ConnectionService::class)->disconnect($this->connection);
        $this->assertNull($this->connection->fresh()->encrypted_credential_reference);
        $this->bookViaWeb(email: 'z@example.com');
        $this->assertSame([], $this->wa->sent);
        Mail::assertQueued(AppointmentNotificationMail::class);
    }

    public function test_free_form_messages_are_not_sent_outside_24h_window(): void
    {
        $this->setUpWhatsApp();
        $a = $this->bookViaWeb();
        $row = app(WhatsAppSender::class)->queueText($this->connection, $a, self::CUSTOMER, 'Merhaba', 'test');
        $this->assertNull($row, 'customer never wrote to us -> only templates allowed');
    }

    public function test_reminders_are_sent_once_and_respect_status(): void
    {
        $this->setUpWhatsApp('automatic');
        $this->travelTo($this->at('08:00', '2026-11-02'));
        $a = $this->bookViaWeb('18:00');
        $this->travelTo($this->at('17:59', '2026-11-01')); // impossible past, sanity: ignored
        $this->travelTo($this->at('09:00', '2026-11-02'));
        (new SendAppointmentReminders)->handle(app(NotificationDispatcher::class), app(TenantContext::class));
        (new SendAppointmentReminders)->handle(app(NotificationDispatcher::class), app(TenantContext::class));
        $reminders = array_filter($this->wa->sentTo(self::CUSTOMER), fn ($m) => ($m['payload']['name'] ?? '') === 'randevu_hatirlatma');
        $this->assertCount(1, $reminders);
        $this->assertNotNull($a);
    }
}
