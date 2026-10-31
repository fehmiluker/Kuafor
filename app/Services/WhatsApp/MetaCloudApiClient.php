<?php

namespace App\Services\WhatsApp;

use App\DTOs\WhatsApp\HealthResult;
use App\DTOs\WhatsApp\SendResult;
use App\Models\WhatsAppConnection;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta Graph API client: POST /{version}/{phone-number-id}/messages.
 * Requires a live, permissioned connection (WABA, phone_number_id, access token
 * obtained through Embedded Signup / system user). Without live credentials use
 * WHATSAPP_PROVIDER_MODE=fake.
 */
final class MetaCloudApiClient implements WhatsAppClientInterface
{
    public function sendTemplate(WhatsAppConnection $connection, string $toE164, string $templateName, string $language, array $components = []): SendResult
    {
        $template = ['name' => $templateName, 'language' => ['code' => $language]];
        if ($components) {
            $template['components'] = $components;
        }

        return $this->send($connection, ['to' => PhoneNumber::toWaId($toE164), 'type' => 'template', 'template' => $template]);
    }

    public function sendInteractive(WhatsAppConnection $connection, string $toE164, array $interactive): SendResult
    {
        return $this->send($connection, ['to' => PhoneNumber::toWaId($toE164), 'type' => 'interactive', 'interactive' => $interactive]);
    }

    public function sendText(WhatsAppConnection $connection, string $toE164, string $body): SendResult
    {
        return $this->send($connection, ['to' => PhoneNumber::toWaId($toE164), 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]]);
    }

    public function health(WhatsAppConnection $connection): HealthResult
    {
        try {
            $response = $this->http($connection)->get($this->url($connection->phone_number_id), [
                'fields' => 'display_phone_number,quality_rating,verified_name,code_verification_status',
            ]);
        } catch (ConnectionException $e) {
            return new HealthResult(false, error: 'timeout');
        }
        if ($response->failed()) {
            return new HealthResult(false, error: (string) ($response->json('error.code') ?? $response->status()));
        }

        return new HealthResult(true, PhoneNumber::normalize('+'.preg_replace('/\D/', '', (string) $response->json('display_phone_number'))), $response->json('quality_rating'), raw: $response->json() ?? []);
    }

    public function subscribeWebhooks(WhatsAppConnection $connection): bool
    {
        if (! $connection->waba_id) {
            return false;
        }

        return $this->http($connection)->post($this->url($connection->waba_id.'/subscribed_apps'))->successful();
    }

    public function exchangeCode(string $code): string
    {
        try {
            $response = Http::acceptJson()->timeout((int) config('whatsapp.timeout_seconds'))
                ->get($this->url('oauth/access_token'), [
                    'client_id' => config('whatsapp.app_id'),
                    'client_secret' => config('whatsapp.app_secret'),
                    'code' => $code,
                ]);
        } catch (ConnectionException) {
            throw new WhatsAppException('Meta yanıt vermedi.', 'timeout');
        }
        $token = $response->json('access_token');
        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new WhatsAppException('Meta bağlantı kodu doğrulanamadı.', (string) ($response->json('error.code') ?? 'http_'.$response->status()), $response->status());
        }

        return $token;
    }

    public function registerPhoneNumber(WhatsAppConnection $connection, string $pin): bool
    {
        $response = $this->http($connection)->post($this->url($connection->phone_number_id.'/register'), [
            'messaging_product' => 'whatsapp', 'pin' => $pin,
        ]);

        // Already-registered numbers return an error that is safe to ignore.
        return $response->successful() || (string) $response->json('error.code') === '133005';
    }

    private function send(WhatsAppConnection $connection, array $payload): SendResult
    {
        if (! $connection->isUsable()) {
            throw new WhatsAppException('WhatsApp bağlantısı kullanılabilir değil.', 'connection_unusable');
        }
        try {
            $response = $this->http($connection)->post($this->url($connection->phone_number_id.'/messages'), ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $payload);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('WhatsApp API zaman aşımı.', 'timeout');
        }

        return $this->parse($response);
    }

    private function parse(Response $response): SendResult
    {
        if ($response->failed()) {
            throw new WhatsAppException(
                (string) ($response->json('error.message') ?? 'WhatsApp API hatası'),
                (string) ($response->json('error.code') ?? 'http_'.$response->status()),
                $response->status(),
            );
        }
        $id = $response->json('messages.0.id');
        if (! $id) {
            throw new WhatsAppException('WhatsApp API mesaj kimliği döndürmedi.', 'no_message_id', $response->status());
        }

        return new SendResult($id, $response->json() ?? []);
    }

    private function http(WhatsAppConnection $connection): PendingRequest
    {
        return Http::withToken((string) $connection->encrypted_credential_reference)
            ->acceptJson()
            ->timeout((int) config('whatsapp.timeout_seconds'))
            ->connectTimeout(5);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.graph_base_url'), '/').'/'.config('whatsapp.graph_version').'/'.ltrim($path, '/');
    }
}
