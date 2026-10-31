<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Appointment;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Notifications\OutboundMessage;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Persists an outbound message row (idempotency key) and queues delivery.
 * The row only stores a redacted payload; the parameters travel in the job.
 */
final class WhatsAppSender
{
    public function queueTemplate(WhatsAppConnection $connection, ?Appointment $appointment, OutboundMessage $msg): ?WhatsAppMessage
    {
        $definition = TemplateCatalog::get($msg->templateKey);
        $template = WhatsAppTemplate::where('key', $msg->templateKey)->where('language', 'tr')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($appointment?->tenant_id ?? $connection->tenant_id, fn ($w, $t) => $w->orWhere('tenant_id', $t)))
            ->orderByRaw('tenant_id IS NULL')->first();
        if (! $template || ! $template->isApproved()) {
            return null; // caller falls back to another channel
        }
        $key = $msg->dedupeKey ?? ($msg->templateKey.':'.($appointment?->id ?? 'x').':'.PhoneNumber::hash($msg->recipientE164).':'.now()->format('YmdHis').bin2hex(random_bytes(3)));
        try {
            $row = WhatsAppMessage::withoutGlobalScopes()->create([
                'tenant_id' => $appointment?->tenant_id ?? $connection->tenant_id, 'branch_id' => $appointment?->branch_id ?? $connection->branch_id, 'appointment_id' => $appointment?->id,
                'connection_id' => $connection->id, 'recipient_e164' => $msg->recipientE164, 'direction' => 'outbound',
                'template_id' => $template->id, 'idempotency_key' => $key, 'message_type' => 'template',
                'event_type' => $msg->templateKey, 'status' => MessageStatus::Queued,
                'payload_redacted' => ['template' => $template->name, 'params' => count($msg->params), 'buttons' => count($msg->buttonPayloads)],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already queued by an earlier attempt of the same notification: treat as handled.
            return WhatsAppMessage::withoutGlobalScopes()->where('idempotency_key', $key)->first();
        }
        SendWhatsAppMessage::dispatch($row->id, [
            'kind' => 'template', 'name' => $template->name, 'language' => $template->language,
            'components' => TemplateCatalog::components($msg->params, $msg->buttonPayloads),
        ])->afterCommit();

        return $row;
    }

    /** Interactive/text messages are only allowed inside the 24h customer-service window. */
    public function queueInteractive(WhatsAppConnection $connection, ?Appointment $appointment, string $toE164, array $interactive, string $eventType): ?WhatsAppMessage
    {
        if (! $this->insideServiceWindow($connection, $toE164)) {
            return null;
        }
        $row = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $appointment?->tenant_id ?? $connection->tenant_id, 'branch_id' => $appointment?->branch_id ?? $connection->branch_id, 'appointment_id' => $appointment?->id,
            'connection_id' => $connection->id, 'recipient_e164' => $toE164, 'direction' => 'outbound',
            'idempotency_key' => $eventType.':'.($appointment?->id ?? 'x').':'.bin2hex(random_bytes(8)),
            'message_type' => $interactive['type'] ?? 'interactive', 'event_type' => $eventType, 'status' => MessageStatus::Queued,
            'payload_redacted' => ['interactive_type' => $interactive['type'] ?? null],
        ]);
        SendWhatsAppMessage::dispatch($row->id, ['kind' => 'interactive', 'interactive' => $interactive])->afterCommit();

        return $row;
    }

    public function queueText(WhatsAppConnection $connection, ?Appointment $appointment, string $toE164, string $body, string $eventType): ?WhatsAppMessage
    {
        if (! $this->insideServiceWindow($connection, $toE164)) {
            return null;
        }
        $row = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $appointment?->tenant_id ?? $connection->tenant_id, 'branch_id' => $appointment?->branch_id ?? $connection->branch_id, 'appointment_id' => $appointment?->id,
            'connection_id' => $connection->id, 'recipient_e164' => $toE164, 'direction' => 'outbound',
            'idempotency_key' => $eventType.':'.($appointment?->id ?? 'x').':'.bin2hex(random_bytes(8)),
            'message_type' => 'text', 'event_type' => $eventType, 'status' => MessageStatus::Queued,
        ]);
        SendWhatsAppMessage::dispatch($row->id, ['kind' => 'text', 'body' => $body])->afterCommit();

        return $row;
    }

    public function insideServiceWindow(WhatsAppConnection $connection, string $e164): bool
    {
        return WhatsAppMessage::withoutGlobalScopes()->where('connection_id', $connection->id)
            ->where('direction', 'inbound')->where('recipient_e164', $e164)
            ->where('created_at', '>=', now()->subHours((int) config('whatsapp.service_window_hours')))
            ->exists();
    }
}
