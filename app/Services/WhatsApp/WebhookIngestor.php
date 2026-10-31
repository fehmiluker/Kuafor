<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Splits a verified webhook POST into dedupe-able events and queues them.
 * Duplicate deliveries (Meta retries) hit the unique provider_event_key and
 * are dropped. Payloads are stored encrypted and purged after the retention period.
 */
final class WebhookIngestor
{
    /** @return int number of NEW events queued */
    public function ingest(array $payload): int
    {
        $queued = 0;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $connectionId = $phoneNumberId ? WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)->value('id') : null;
                $items = [];
                foreach ($value['messages'] ?? [] as $m) {
                    $items[] = ['key' => 'msg:'.($m['id'] ?? sha1(json_encode($m))), 'kind' => 'message', 'data' => $m, 'contacts' => $value['contacts'] ?? []];
                }
                foreach ($value['statuses'] ?? [] as $s) {
                    $items[] = ['key' => 'status:'.($s['id'] ?? '').':'.($s['status'] ?? '').':'.($s['timestamp'] ?? ''), 'kind' => 'status', 'data' => $s];
                }
                if ($items === [] && ($change['field'] ?? null)) {
                    $items[] = ['key' => 'field:'.$change['field'].':'.sha1(json_encode($value)), 'kind' => 'other', 'data' => ['field' => $change['field']]];
                }
                foreach ($items as $item) {
                    try {
                        $event = WhatsAppWebhookEvent::create([
                            'provider_event_key' => mb_substr($item['key'], 0, 190),
                            'connection_id' => $connectionId,
                            'payload_encrypted_or_redacted' => ['phone_number_id' => $phoneNumberId] + $item,
                            'status' => $connectionId ? 'received' : 'ignored',
                            'received_at' => now(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        continue; // duplicate delivery
                    }
                    if ($connectionId) {
                        ProcessWhatsAppWebhook::dispatch($event->id)->afterCommit();
                        $queued++;
                    }
                }
            }
        }

        return $queued;
    }
}
