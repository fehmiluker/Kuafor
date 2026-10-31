<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/**
 * SaaS subscription payment provider (NOT used for customer appointments —
 * salons collect service fees in person). Implement one class per provider
 * (iyzico, PayTR, Stripe...) and register it in config/subscriptions.php.
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    public function verifyWebhook(Request $request): bool;

    /**
     * @return array{event_id: string, subscription_id: int, status: string, amount: ?string, reference: ?string}
     */
    public function parseWebhook(Request $request): array;

    /** Whether the provider can actually charge cards (false => manual activation only). */
    public function supportsAutomaticCharging(): bool;
}
