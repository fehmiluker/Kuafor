<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/**
 * SaaS subscription payment provider (NOT used for customer appointments —
 * salons collect service fees in person). Implement one class per provider
 * (iyzico, PayTR, Stripe...) and bind it in AppServiceProvider by
 * config('subscriptions.provider'). Card-page providers also implement HostedCheckoutGateway.
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    public function verifyWebhook(Request $request): bool;

    /**
     * Providers may add keys (e.g. PayTR: total_amount_minor, failure, test_mode).
     *
     * @return array{event_id: string, subscription_id: int, status: string, amount: ?string, reference: ?string}
     */
    public function parseWebhook(Request $request): array;

    /** Whether the provider can actually charge cards (false => manual activation only). */
    public function supportsAutomaticCharging(): bool;
}
