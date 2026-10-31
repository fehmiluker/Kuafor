<?php

namespace Tests\Feature;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentStatus;
use App\Enums\TenantRole;
use App\Jobs\SendReviewInvite;
use App\Livewire\Public\BookingWizard;
use App\Models\Appointment;
use App\Services\Booking\AppointmentService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Settings\SettingsRepository;
use App\Services\WhatsApp\ConnectionService;
use App\Services\WhatsApp\FakeWhatsAppClient;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Support\PhoneNumber;
use Livewire\Livewire;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class EndToEndTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function tap(string $from, string $payload): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '200000000000001'],
            'messages' => [['from' => PhoneNumber::toWaId($from), 'id' => 'wamid.'.bin2hex(random_bytes(6)), 'type' => 'button', 'button' => ['payload' => $payload]]],
        ]]]]]]);
        $this->call('POST', '/webhooks/meta/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret')], $body)->assertOk();
    }

    public function test_customer_journey_with_whatsapp_salon_approval_and_verified_review(): void
    {
        $this->buildSalon('manual');
        app(ConnectionService::class)->connect($this->branch, '100000000000001', '200000000000001', 'EAAG-test-token-123456', '+902161112233');
        app(SettingsRepository::class)->setBranch($this->branch, ['whatsapp_manager_numbers' => ['+905321110002']]);
        /** @var FakeWhatsAppClient $wa */
        $wa = app(WhatsAppClientInterface::class);

        // 1. Search -> salon page (mobile visitor)
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')->get('/salonlar?kategori=sac-kesim')->assertOk()->assertSee($this->tenant->name);
        $this->get('/salon/'.$this->tenant->slug)->assertOk()->assertSee('Randevu al');

        // 2. Booking wizard (single branch -> starts at services)
        $wizard = Livewire::test(BookingWizard::class, ['slug' => $this->tenant->slug])
            ->assertSet('step', 2)
            ->call('toggleService', $this->cut->id)->call('toEmployees')->assertSet('step', 3)
            ->call('selectEmployee', null)->assertSet('step', 4)
            ->assertSee('09:00')
            ->call('selectTime', '11:00')->assertSet('step', 5)
            ->set('fullName', 'Zeynep Kaya')->set('phone', '0555 111 22 33')->set('kvkk', true)->set('whatsappOptIn', true)
            ->call('toSummary')->assertSet('step', 6)->assertSee('Online ödeme veya kapora alınmaz')
            ->call('confirm')->assertSet('step', 7)->assertSee('Talebiniz alındı');
        $a = Appointment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(AppointmentStatus::Pending, $a->status);
        $this->assertNotNull($wizard->get('result')['manage_url']);
        // Double-submit of the same wizard is idempotent.
        $wizard->call('confirm');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());

        // 3. Salon approves from WhatsApp
        $salonMsg = collect($wa->sentTo('+905321110002'))->firstWhere('payload.name', 'salon_yeni_talep');
        [$approve] = FakeWhatsAppClient::actionPayloads($salonMsg);
        $this->tap('+905321110002', $approve);
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);

        // 4. Customer is notified from the branch number
        $this->assertContains('randevu_onaylandi', array_map(fn ($m) => $m['payload']['name'] ?? null, $wa->sentTo('+905551112233')));

        // 5. Visit happens, salon completes and records in-salon payment
        $this->travelTo($this->at('11:40'));
        $this->actingAs($this->owner($this->tenant))
            ->post(route('tenant.appointments.act', [$a->id, 'tamamla']), ['amount' => '300.00', 'method' => 'cash'])->assertRedirect();
        $this->assertSame(AppointmentStatus::Completed, $a->fresh()->status);

        // 6. Review invite (WhatsApp, with a scoped, expiring link) -> verified review
        (new SendReviewInvite($a->id))->handle(app(NotificationDispatcher::class));
        $invite = collect($wa->sentTo('+905551112233'))->firstWhere('payload.name', 'degerlendirme_daveti');
        $url = $invite['payload']['components'][0]['parameters'][2]['text'];
        $this->assertStringContainsString('/randevu/'.$a->public_reference.'/degerlendir?token=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        auth()->logout();
        $this->post(route('guest.review.store', $a->public_reference), ['token' => $q['token'], 'rating' => 5, 'body' => 'Harika'])->assertRedirect();
        $this->get('/salon/'.$this->tenant->slug)->assertSee('Harika')->assertSee('Doğrulanmış');
    }

    public function test_automatic_mode_and_alternative_time_via_web_links(): void
    {
        $this->buildSalon('automatic');
        Livewire::test(BookingWizard::class, ['slug' => $this->tenant->slug])
            ->call('toggleService', $this->cut->id)->call('toEmployees')->call('selectEmployee', $this->ayse->id)
            ->call('selectTime', '10:00')->set('fullName', 'Ali Veli')->set('phone', '05559998877')->set('kvkk', true)
            ->call('toSummary')->call('confirm')->assertSee('Randevunuz onaylandı');

        // Manual salon + proposal answered through the e-mailed web link
        $this->builder->settings(['approval_mode' => 'manual']);
        $a = $this->book($this->at('14:00'), $this->ayse->id, phone: '+905551230000')['appointment'];
        $this->actingAs($this->owner($this->tenant))->post(route('tenant.appointments.act', [$a->id, 'oner']), ['date' => '2026-11-02', 'time' => '16:00'])->assertRedirect();
        $r = app(AppointmentService::class)->propose($a->fresh(), $this->at('16:30'), null, Actor::system('t'));
        auth()->logout();
        $this->get(route('proposal.show', ['proposal' => $r['proposal']->id, 'token' => $r['token']]))->assertOk()->assertSee('Kabul et');
        $this->post(route('proposal.respond', [$r['proposal']->id, 'kabul']), ['token' => $r['token']])->assertRedirect();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertSame($this->at('16:30')->toDateTimeString(), $a->fresh()->starts_at_utc->toDateTimeString());
    }

    public function test_calendar_feed_and_drag_drop_revalidates(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->book($this->at('12:00'), $this->ayse->id, phone: '+905550001111');
        $owner = $this->owner($this->tenant);
        $events = $this->actingAs($owner)->getJson('/panel/takvim/olaylar?start=2026-11-02T00:00:00&end=2026-11-03T00:00:00&branch_id='.$this->branch->id)->assertOk()->json();
        $this->assertCount(2, $events);
        $this->assertSame('2026-11-02T10:00:00', $events[0]['start'], 'branch-local time');
        $this->actingAs($owner)->postJson("/panel/randevular/{$a->id}/surukle", ['start' => '2026-11-02T12:15:00'])->assertStatus(409)->assertJsonPath('ok', false);
        $this->actingAs($owner)->postJson("/panel/randevular/{$a->id}/surukle", ['start' => '2026-11-02T15:00:00'])->assertOk()->assertJsonPath('start', '2026-11-02T15:00:00');
        $employee = $this->member($this->tenant, TenantRole::Employee, [$this->branch->id]);
        $this->actingAs($employee)->postJson("/panel/randevular/{$a->id}/surukle", ['start' => '2026-11-02T16:00:00'])->assertForbidden();
    }
}
