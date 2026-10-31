<?php

namespace App\Services\WhatsApp;

use App\DTOs\WhatsApp\HealthResult;
use App\DTOs\WhatsApp\SendResult;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sandbox implementation (WHATSAPP_PROVIDER_MODE=fake): no network. Records
 * every outbound payload so tests and the local demo can inspect them.
 * It never pretends a real message was delivered — messages stay "sent".
 */
final class FakeWhatsAppClient implements WhatsAppClientInterface
{
    /** @var list<array{type:string, to:string, connection_id:int, payload:array}> */
    public array $sent = [];

    /** Queue of exceptions to throw on the next sends (for failure tests). */
    public array $failNext = [];

    public function sendTemplate(WhatsAppConnection $connection, string $toE164, string $templateName, string $language, array $components = []): SendResult
    {
        return $this->record($connection, 'template', $toE164, ['name' => $templateName, 'language' => $language, 'components' => $components]);
    }

    public function sendInteractive(WhatsAppConnection $connection, string $toE164, array $interactive): SendResult
    {
        return $this->record($connection, 'interactive', $toE164, $interactive);
    }

    public function sendText(WhatsAppConnection $connection, string $toE164, string $body): SendResult
    {
        return $this->record($connection, 'text', $toE164, ['body' => $body]);
    }

    public function health(WhatsAppConnection $connection): HealthResult
    {
        return new HealthResult($connection->encrypted_credential_reference !== 'invalid-token', $connection->display_phone, 'GREEN',
            $connection->encrypted_credential_reference === 'invalid-token' ? '190' : null);
    }

    public function subscribeWebhooks(WhatsAppConnection $connection): bool
    {
        return true;
    }

    public function exchangeCode(string $code): string
    {
        if ($code === 'invalid') {
            throw new WhatsAppException('Meta bağlantı kodu doğrulanamadı.', '100', 400);
        }

        return 'sandbox-es-token-'.substr(hash('sha256', $code), 0, 32);
    }

    public function registerPhoneNumber(WhatsAppConnection $connection, string $pin): bool
    {
        return true;
    }

    private function record(WhatsAppConnection $connection, string $type, string $to, array $payload): SendResult
    {
        if ($this->failNext !== []) {
            throw array_shift($this->failNext);
        }
        if (! $connection->isUsable()) {
            throw new WhatsAppException('WhatsApp bağlantısı kullanılabilir değil.', 'connection_unusable');
        }
        if ($connection->encrypted_credential_reference === 'invalid-token') {
            throw new WhatsAppException('Invalid OAuth access token.', '190', 401);
        }
        $this->sent[] = ['type' => $type, 'to' => $to, 'connection_id' => $connection->id, 'payload' => $payload];
        Log::channel(config('logging.default'))->debug('[whatsapp:fake] '.$type.' message recorded', ['connection_id' => $connection->id, 'type' => $type]);

        return new SendResult('wamid.FAKE'.Str::upper(Str::random(24)));
    }

    /** @return list<array> */
    public function sentTo(string $e164): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['to'] === $e164));
    }

    /** Extract all "a:{token}" button/list payload ids from a recorded message. */
    public static function actionPayloads(array $message): array
    {
        $json = json_encode($message['payload']);
        preg_match_all('/"(?:payload|id)":"(a:[A-Za-z0-9]+)"/', $json, $m);

        return $m[1];
    }
}
