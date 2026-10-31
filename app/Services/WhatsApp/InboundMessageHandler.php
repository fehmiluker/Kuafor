<?php

namespace App\Services\WhatsApp;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidTransitionException;
use App\Models\ActionToken;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\Booking\ActionTokenService;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\Exclusions;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tenancy\TenantContext;
use App\Support\LocalTime;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Applies inbound WhatsApp events:
 *  - delivery statuses (monotonic: never move a message backwards),
 *  - button / list replies carrying "a:{token}" one-time action payloads.
 *
 * Security: the token must (1) exist, be unused and unexpired, (2) belong to the
 * SAME tenant as the receiving connection, (3) have been issued to the SENDER's
 * number. Only then is the action executed, with fresh availability checks.
 */
final class InboundMessageHandler
{
    public function __construct(
        private readonly ActionTokenService $tokens,
        private readonly AppointmentService $appointments,
        private readonly AvailabilityService $availability,
        private readonly WhatsAppSender $sender,
        private readonly NotificationDispatcher $notifications,
        private readonly TenantContext $context,
    ) {}

    public function handle(WhatsAppWebhookEvent $event): void
    {
        $data = $event->payload_encrypted_or_redacted;
        $connection = WhatsAppConnection::withoutGlobalScopes()->find($event->connection_id);
        if (! $connection) {
            return;
        }
        $tenant = Tenant::findOrFail($connection->tenant_id);
        $this->context->run($tenant, function () use ($data, $connection) {
            match ($data['kind'] ?? null) {
                'status' => $this->applyStatus($connection, $data['data']),
                'message' => $this->applyMessage($connection, $data['data']),
                default => null,
            };
        });
    }

    private function applyStatus(WhatsAppConnection $connection, array $s): void
    {
        $message = WhatsAppMessage::withoutGlobalScopes()->where('connection_id', $connection->id)->where('provider_message_id', $s['id'] ?? '')->first();
        $new = MessageStatus::tryFrom((string) ($s['status'] ?? ''));
        if (! $message || ! $new) {
            return;
        }
        $at = isset($s['timestamp']) ? CarbonImmutable::createFromTimestampUTC((int) $s['timestamp']) : now();
        $attrs = [];
        if ($new === MessageStatus::Failed) {
            $attrs = ['status' => MessageStatus::Failed, 'error_code' => (string) ($s['errors'][0]['code'] ?? 'unknown'), 'error_message' => mb_substr((string) ($s['errors'][0]['title'] ?? ''), 0, 500)];
        } elseif ($new->rank() > $message->status->rank() || $message->status === MessageStatus::Queued) {
            $attrs['status'] = $new;
        }
        if ($new === MessageStatus::Delivered && ! $message->delivered_at) {
            $attrs['delivered_at'] = $at;
        }
        if ($new === MessageStatus::Read && ! $message->read_at) {
            $attrs['read_at'] = $at;
        }
        if ($attrs) {
            $message->forceFill($attrs)->save();
        }
        if ($new === MessageStatus::Failed && $message->wasChanged('status')) {
            $this->notifications->fallbackForFailedWhatsApp($message);
        }
    }

    private function applyMessage(WhatsAppConnection $connection, array $m): void
    {
        $from = PhoneNumber::fromWaId((string) ($m['from'] ?? ''));
        if (! $from) {
            return;
        }
        // Record inbound (opens the 24h service window). Unique wamid makes replays harmless.
        try {
            WhatsAppMessage::withoutGlobalScopes()->create([
                'tenant_id' => $connection->tenant_id, 'branch_id' => $connection->branch_id, 'connection_id' => $connection->id,
                'recipient_e164' => $from, 'direction' => 'inbound', 'provider_message_id' => $m['id'] ?? null,
                'idempotency_key' => 'in:'.($m['id'] ?? bin2hex(random_bytes(8))), 'message_type' => (string) ($m['type'] ?? 'unknown'),
                'status' => MessageStatus::Received, 'payload_redacted' => ['type' => $m['type'] ?? null],
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // already handled
        }

        $payload = $m['button']['payload']
            ?? $m['interactive']['button_reply']['id']
            ?? $m['interactive']['list_reply']['id']
            ?? null;
        if (! is_string($payload) || ! str_starts_with($payload, 'a:')) {
            return; // free text: visible in the inbox; no automated action
        }
        $plain = substr($payload, 2);
        $peek = $this->tokens->peek($plain);
        if (! $peek || $peek->tenant_id !== $connection->tenant_id) {
            $this->reply($connection, $from, 'Bu işlem bağlantısı geçersiz veya süresi dolmuş.');

            return;
        }
        $token = $this->tokens->consume($plain, null, $from);
        if (! $token) {
            $this->reply($connection, $from, 'Bu işlem zaten yapılmış ya da bu numara için yetkili değil.');

            return;
        }
        $appointment = Appointment::withoutGlobalScopes()->where('tenant_id', $connection->tenant_id)->find($token->appointment_id);
        if (! $appointment) {
            return;
        }

        try {
            match ($token->purpose) {
                ActionToken::SALON_APPROVE => $this->salonApprove($connection, $appointment, $from),
                ActionToken::SALON_PROPOSE => $this->sendSlotPicker($connection, $appointment, $from),
                ActionToken::SALON_PROPOSE_SLOT => $this->salonPropose($connection, $appointment, $from, $token),
                ActionToken::SALON_REJECT => $this->salonReject($connection, $appointment, $from),
                ActionToken::CUSTOMER_ACCEPT, ActionToken::CUSTOMER_REJECT => $this->customerRespond($connection, $appointment, $from, $token),
                default => null,
            };
        } catch (BookingConflictException $e) {
            $this->reply($connection, $from, 'Seçilen saat artık müsait değil. Lütfen panelden başka bir saat seçin.');
        } catch (DomainRuleException|InvalidTransitionException $e) {
            $this->reply($connection, $from, $e->getMessage());
        }
    }

    private function salonApprove(WhatsAppConnection $c, Appointment $a, string $from): void
    {
        $this->appointments->approve($a, Actor::whatsapp());
        $this->reply($c, $from, 'Randevu onaylandı ✅ Müşteriye bilgi verildi.', $a);
    }

    private function salonReject(WhatsAppConnection $c, Appointment $a, string $from): void
    {
        $this->appointments->reject($a, Actor::whatsapp(), 'Salon WhatsApp üzerinden reddetti.');
        $this->reply($c, $from, 'Talep reddedildi.', $a);
    }

    /** Interactive list of REAL available slots (each row = one-time token). */
    private function sendSlotPicker(WhatsAppConnection $c, Appointment $a, string $from): void
    {
        if (! in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed], true)) {
            $this->reply($c, $from, 'Bu talep artık yanıt beklemiyor.');

            return;
        }
        $branch = Branch::withoutGlobalScopes()->with('tenant')->findOrFail($a->branch_id);
        $serviceIds = $a->items()->withoutGlobalScopes()->pluck('service_id')->filter()->all();
        $rows = [];
        $date = LocalTime::toLocal($a->starts_at_utc, $branch->timezone)->startOfDay();
        for ($d = 0; $d < 7 && count($rows) < 10; $d++) {
            $day = $date->addDays($d)->toDateString();
            foreach ($this->availability->slotsForDay($branch, $serviceIds, null, $day, false, new Exclusions($a->id)) as $slot) {
                if ($slot['start']->equalTo($a->starts_at_utc) || count($rows) >= 10) {
                    continue;
                }
                $employeeId = in_array($a->employee_id, $slot['employee_ids'], true) ? $a->employee_id : $slot['employee_ids'][0];
                $token = $this->tokens->issue($a, ActionToken::SALON_PROPOSE_SLOT, 'salon', $from, ['start' => $slot['start']->toIso8601String(), 'employee_id' => $employeeId]);
                $local = LocalTime::toLocal($slot['start'], $branch->timezone);
                $rows[] = ['id' => 'a:'.$token, 'title' => mb_substr($local->locale('tr')->translatedFormat('d M D H:i'), 0, 24),
                    'description' => mb_substr((string) Employee::withoutGlobalScopes()->whereKey($employeeId)->value('display_name'), 0, 72)];
            }
        }
        if ($rows === []) {
            $this->reply($c, $from, 'Önümüzdeki 7 gün için uygun saat bulunamadı. Lütfen panelden düzenleyin.');

            return;
        }
        $this->sender->queueInteractive($c, $a, $from, [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => 'Alternatif saat seçin'],
            'body' => ['text' => 'Müşteriye önerilecek saati seçin. Seçiminiz müşteriye onay için gönderilir.'],
            'action' => ['button' => 'Saatleri gör', 'sections' => [['title' => 'Uygun saatler', 'rows' => $rows]]],
        ], 'salon_slot_picker');
    }

    private function salonPropose(WhatsAppConnection $c, Appointment $a, string $from, ActionToken $token): void
    {
        $start = CarbonImmutable::parse((string) ($token->payload['start'] ?? ''));
        $this->appointments->propose($a, $start, (int) ($token->payload['employee_id'] ?? $a->employee_id), Actor::whatsapp());
        $this->tokens->invalidateForAppointment($a->id, [ActionToken::SALON_PROPOSE_SLOT]);
        $this->reply($c, $from, 'Alternatif saat müşteriye gönderildi.', $a);
    }

    private function customerRespond(WhatsAppConnection $c, Appointment $a, string $from, ActionToken $token): void
    {
        $proposal = AppointmentProposal::withoutGlobalScopes()->where('tenant_id', $a->tenant_id)->findOrFail($token->proposal_id);
        $accept = $token->purpose === ActionToken::CUSTOMER_ACCEPT;
        $this->appointments->respondToProposal($proposal, $accept, Actor::whatsapp());
        $this->reply($c, $from, $accept ? 'Teşekkürler! Randevunuz onaylandı ✅' : 'Teklifi reddettiniz. Dilediğiniz zaman yeni randevu alabilirsiniz.', $a);
    }

    /** Free-form reply is valid: the sender just messaged us (24h window open). */
    private function reply(WhatsAppConnection $c, string $to, string $text, ?Appointment $a = null): void
    {
        $this->sender->queueText($c, $a, $to, $text, 'action_reply');
    }
}
