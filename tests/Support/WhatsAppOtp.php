<?php

namespace Tests\Support;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppClientInterface;

/** Phone verification codes travel over WhatsApp (no SMS): a connected platform number + the fake client's outbox. */
trait WhatsAppOtp
{
    protected function connectPlatformWhatsApp(): WhatsAppConnection
    {
        return WhatsAppConnection::platform() ?? WhatsAppConnection::withoutGlobalScopes()->create([
            'tenant_id' => null, 'branch_id' => null, 'is_platform' => true, 'onboarding_method' => 'platform',
            'waba_id' => '100000000000099', 'phone_number_id' => '200000000000099', 'display_phone' => '+908501112233',
            'encrypted_credential_reference' => 'sandbox-platform-token', 'status' => 'connected', 'connected_at' => now(), 'webhook_subscribed' => true,
        ]);
    }

    /** Last one-time code sent to this number (from the AUTHENTICATION template's body parameter). */
    protected function lastOtp(string $e164): ?string
    {
        $messages = array_filter(app(WhatsAppClientInterface::class)->sentTo($e164), fn ($m) => ($m['payload']['name'] ?? null) === 'dogrulama_kodu');
        $last = end($messages);

        return $last ? $last['payload']['components'][0]['parameters'][0]['text'] ?? null : null;
    }
}
