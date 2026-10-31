<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent delivery of one outbound message row. Retries with backoff for
 * transient provider errors; permanent errors mark the row failed and trigger
 * the e-mail/panel fallback. Exhausted retries land in failed_jobs (dead letter).
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $messageId, public readonly array $content)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(WhatsAppClientInterface $client): void
    {
        // Claim the row: only a queued row is sent, and only once.
        $claimed = DB::table('whatsapp_messages')->where('id', $this->messageId)
            ->where('status', MessageStatus::Queued->value)->increment('attempts');
        if ($claimed === 0) {
            return;
        }
        $message = WhatsAppMessage::withoutGlobalScopes()->findOrFail($this->messageId);
        if ($message->provider_message_id) {
            return; // sent by an earlier attempt that crashed before the status update
        }
        $connection = WhatsAppConnection::withoutGlobalScopes()->find($message->connection_id);
        if (! $connection || ! $connection->isUsable()) {
            $this->markFailed($message, 'connection_unusable', 'Şube WhatsApp bağlantısı aktif değil.');

            return;
        }
        try {
            $result = match ($this->content['kind']) {
                'template' => $client->sendTemplate($connection, $message->recipient_e164, $this->content['name'], $this->content['language'], $this->content['components']),
                'interactive' => $client->sendInteractive($connection, $message->recipient_e164, $this->content['interactive']),
                'text' => $client->sendText($connection, $message->recipient_e164, $this->content['body']),
            };
        } catch (WhatsAppException $e) {
            if ($e->isAuthError()) {
                $connection->forceFill(['status' => WhatsAppConnectionStatus::Error, 'last_error' => 'Yetki hatası ('.$e->errorCode.')'])->save();
                $this->markFailed($message, $e->errorCode, $e->getMessage());

                return;
            }
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $message->forceFill(['error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500)])->save();
                $this->release($this->backoff()[min($this->attempts() - 1, 3)]);

                return;
            }
            $this->markFailed($message, $e->errorCode, $e->getMessage());

            return;
        }
        $message->forceFill([
            'provider_message_id' => $result->providerMessageId,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
            'error_code' => null, 'error_message' => null,
        ])->save();
    }

    private function markFailed(WhatsAppMessage $message, string $code, string $error): void
    {
        $message->forceFill(['status' => MessageStatus::Failed, 'error_code' => $code, 'error_message' => mb_substr($error, 0, 500)])->save();
        Log::warning('whatsapp.send_failed', ['message_id' => $message->id, 'code' => $code]);
        app(NotificationDispatcher::class)->fallbackForFailedWhatsApp($message);
    }
}
