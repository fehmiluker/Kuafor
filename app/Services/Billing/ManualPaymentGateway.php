<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/**
 * "manual" provider: payments are activated by a super admin (bank transfer,
 * invoice) or via a signed internal webhook (X-Signature: sha256 HMAC of the raw
 * body with SUBSCRIPTION_WEBHOOK_SECRET). It never charges anyone.
 */
final class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'manual';
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('subscriptions.webhook_secret');
        $sig = (string) $request->header('X-Signature');

        return $secret !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig);
    }

    public function parseWebhook(Request $request): array
    {
        $p = $request->json()->all();

        return [
            'event_id' => (string) ($p['event_id'] ?? ''),
            'subscription_id' => (int) ($p['subscription_id'] ?? 0),
            'status' => (string) ($p['status'] ?? ''),
            'amount' => isset($p['amount']) ? (string) $p['amount'] : null,
            'reference' => isset($p['reference']) ? (string) $p['reference'] : null,
        ];
    }

    public function supportsAutomaticCharging(): bool
    {
        return false;
    }
}
