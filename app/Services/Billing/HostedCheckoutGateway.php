<?php

namespace App\Services\Billing;

use App\Exceptions\DomainRuleException;
use App\Models\SubscriptionCheckout;
use Symfony\Component\HttpFoundation\Response;

/**
 * A provider whose payment page is embedded/hosted by the provider itself
 * (PayTR iFrame): card data never touches our servers. The result arrives
 * asynchronously at the provider callback, which is the only source of truth.
 */
interface HostedCheckoutGateway extends PaymentGatewayInterface
{
    /**
     * Ask the provider for a payment session and return its token.
     *
     * @param  array{email: string, name: string, address: string, phone: string, ip: string, description: string, ok_url: string, fail_url: string}  $buyer
     *
     * @throws DomainRuleException when the provider refuses the request
     */
    public function createSession(SubscriptionCheckout $checkout, array $buyer): string;

    /** URL to embed in the payment page iframe. */
    public function iframeUrl(string $token): string;

    /** Origins the payment page must allow (CSP script-src / frame-src). */
    public function cspOrigins(): array;

    /** Body the provider expects after a processed callback (PayTR: plain "OK"). */
    public function acknowledge(): Response;

    /** Minutes a session stays payable. */
    public function sessionMinutes(): int;
}
