<?php

namespace App\Jobs;

use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\InboundMessageHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(InboundMessageHandler $handler): void
    {
        // Claim: only one worker processes an event; processed events are never re-run.
        $claimed = DB::table('whatsapp_webhook_events')->where('id', $this->eventId)
            ->whereIn('status', ['received', 'failed'])->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $event = WhatsAppWebhookEvent::findOrFail($this->eventId);
        try {
            $handler->handle($event);
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $e) {
            $event->forceFill(['status' => 'failed', 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
            Log::error('whatsapp.webhook_failed', ['event_id' => $event->id, 'error' => class_basename($e)]);
            throw $e; // retry with backoff; exhausted -> failed_jobs (dead letter)
        }
    }
}
