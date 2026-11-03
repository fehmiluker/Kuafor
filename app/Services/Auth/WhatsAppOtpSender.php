<?php

namespace App\Services\Auth;

use App\Enums\MessageStatus;
use App\Exceptions\DomainRuleException;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateCatalog;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Sends one-time codes over WhatsApp (there is no SMS channel). Uses the
 * platform number and the Meta AUTHENTICATION template ("Kodu kopyala" button).
 * Sent synchronously: a code must not wait for the queue worker.
 */
final class WhatsAppOtpSender
{
    public function __construct(private readonly WhatsAppClientInterface $client) {}

    public function available(): bool
    {
        return $this->connection() !== null && $this->template() !== null;
    }

    public function send(string $phoneE164, string $code): void
    {
        $connection = $this->connection();
        $template = $this->template();
        if (! $connection || ! $template) {
            throw new DomainRuleException('WhatsApp ile doğrulama şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin veya salonu arayın.', 'otp_unavailable');
        }
        $row = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => null, 'branch_id' => null, 'appointment_id' => null, 'connection_id' => $connection->id,
            'recipient_e164' => $phoneE164, 'direction' => 'outbound', 'template_id' => $template->id,
            'idempotency_key' => 'otp:'.PhoneNumber::hash($phoneE164).':'.bin2hex(random_bytes(8)),
            'message_type' => 'template', 'event_type' => 'otp_code', 'status' => MessageStatus::Queued,
            'payload_redacted' => ['template' => $template->name], // never the code
        ]);
        try {
            $result = $this->client->sendTemplate($connection, $phoneE164, $template->name, $template->language, TemplateCatalog::otpComponents($code));
            $row->forceFill(['status' => MessageStatus::Sent, 'provider_message_id' => $result->providerMessageId, 'sent_at' => now()])->save();
            if (config('whatsapp.mode') !== 'meta') {
                // Sandbox/demo only: nothing is really sent, so testers read the code from the log.
                Log::info('[otp:fake] WhatsApp code for '.PhoneNumber::mask($phoneE164).': '.$code);
            }
        } catch (\Throwable $e) {
            Log::warning('[otp] WhatsApp send failed', ['to' => PhoneNumber::mask($phoneE164), 'error' => $e->getMessage()]);
            $row->forceFill(['status' => MessageStatus::Failed])->save();
            throw new DomainRuleException('Kod WhatsApp ile gönderilemedi. Numaranızın WhatsApp kullandığından emin olup tekrar deneyin.', 'otp_send_failed');
        }
    }

    private function connection(): ?WhatsAppConnection
    {
        $c = WhatsAppConnection::platform();

        return $c && $c->isUsable() ? $c : null;
    }

    private function template(): ?WhatsAppTemplate
    {
        $t = WhatsAppTemplate::whereNull('tenant_id')->where('key', 'otp_code')->where('language', 'tr')->first();

        return $t && $t->isApproved() ? $t : null;
    }
}
