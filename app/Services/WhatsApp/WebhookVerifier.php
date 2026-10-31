<?php

namespace App\Services\WhatsApp;

/** Meta webhook verification: GET challenge + POST X-Hub-Signature-256 over the RAW body. */
final class WebhookVerifier
{
    public function verifyChallenge(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expected = (string) config('whatsapp.verify_token');
        if ($mode !== 'subscribe' || $expected === '' || $token === null || ! hash_equals($expected, $token) || $challenge === null) {
            return null;
        }

        return $challenge;
    }

    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('whatsapp.app_secret');
        if ($secret === '' || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header);
    }
}
