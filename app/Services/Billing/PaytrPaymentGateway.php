<?php

namespace App\Services\Billing;

use App\Exceptions\DomainRuleException;
use App\Models\SubscriptionCheckout;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayTR iFrame API for SaaS subscription payments (salon owners paying the
 * platform — never customers paying for appointments).
 *
 *  1. Server asks /odeme/api/get-token with a signed request → token.
 *  2. Owner pays inside https://www.paytr.com/odeme/guvenli/{token}.
 *  3. PayTR POSTs the result to the "Bildirim URL"
 *     (/webhooks/subscription-payments/paytr); we verify the hash, activate the
 *     subscription and answer plain "OK" (otherwise PayTR keeps retrying).
 *
 * No card storage / recurring charge: every period is paid by the owner
 * (supportsAutomaticCharging() = false).
 */
final class PaytrPaymentGateway implements HostedCheckoutGateway
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('subscriptions.paytr'));
    }

    public function name(): string
    {
        return 'paytr';
    }

    public function supportsAutomaticCharging(): bool
    {
        return false;
    }

    public function sessionMinutes(): int
    {
        return (int) ($this->config['timeout_limit'] ?? 30);
    }

    public function createSession(SubscriptionCheckout $checkout, array $buyer): string
    {
        $this->assertConfigured();
        $amount = $checkout->amount;
        $paymentAmount = (string) $amount->minor; // kuruş, integer
        $basket = base64_encode((string) json_encode([[$buyer['description'], $amount->toDecimal(), 1]], JSON_UNESCAPED_UNICODE));
        $noInstallment = ! empty($this->config['no_installment']) ? '1' : '0';
        $maxInstallment = (string) (int) ($this->config['max_installment'] ?? 0);
        $currency = $amount->currency === 'TRY' ? 'TL' : $amount->currency;
        $testMode = ! empty($this->config['test_mode']) ? '1' : '0';

        // Order is fixed by PayTR: merchant_id user_ip merchant_oid email payment_amount
        // user_basket no_installment max_installment currency test_mode (+ salt).
        $hashStr = $this->config['merchant_id'].$buyer['ip'].$checkout->merchant_oid.$buyer['email'].$paymentAmount
            .$basket.$noInstallment.$maxInstallment.$currency.$testMode;

        $fields = [
            'merchant_id' => $this->config['merchant_id'],
            'user_ip' => $buyer['ip'],
            'merchant_oid' => $checkout->merchant_oid,
            'email' => $buyer['email'],
            'payment_amount' => $paymentAmount,
            'paytr_token' => $this->sign($hashStr.$this->config['merchant_salt']),
            'user_basket' => $basket,
            'debug_on' => ! empty($this->config['debug']) ? '1' : '0',
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr($buyer['name'], 0, 60),
            'user_address' => mb_substr($buyer['address'], 0, 400),
            'user_phone' => mb_substr($buyer['phone'], 0, 20),
            'merchant_ok_url' => $buyer['ok_url'],
            'merchant_fail_url' => $buyer['fail_url'],
            'timeout_limit' => (string) $this->sessionMinutes(),
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ];

        try {
            $response = Http::asForm()->timeout(20)->post(rtrim((string) $this->config['base_url'], '/').'/odeme/api/get-token', $fields);
        } catch (HttpClientException $e) {
            Log::warning('[paytr] get-token connection failed', ['oid' => $checkout->merchant_oid, 'error' => $e->getMessage()]);
            throw new DomainRuleException('Ödeme sağlayıcısına ulaşılamadı. Lütfen biraz sonra tekrar deneyin.', 'payment_unavailable');
        }

        if ($response->json('status') === 'success' && is_string($token = $response->json('token')) && $token !== '') {
            return $token;
        }
        Log::warning('[paytr] get-token refused', ['oid' => $checkout->merchant_oid, 'http' => $response->status(), 'reason' => $response->json('reason') ?? mb_substr($response->body(), 0, 300)]);

        throw new DomainRuleException('Ödeme başlatılamadı. Lütfen biraz sonra tekrar deneyin.', 'payment_refused');
    }

    public function iframeUrl(string $token): string
    {
        return rtrim((string) $this->config['base_url'], '/').'/odeme/guvenli/'.rawurlencode($token);
    }

    public function cspOrigins(): array
    {
        return [rtrim((string) $this->config['base_url'], '/')];
    }

    /** hash = base64(hmac_sha256(merchant_oid + merchant_salt + status + total_amount, merchant_key)) */
    public function verifyWebhook(Request $request): bool
    {
        if (blank($this->config['merchant_key'] ?? null) || blank($this->config['merchant_salt'] ?? null)) {
            return false;
        }
        $given = (string) $request->input('hash', '');
        $expected = $this->sign($request->input('merchant_oid').$this->config['merchant_salt'].$request->input('status').$request->input('total_amount'));

        return $given !== '' && hash_equals($expected, $given);
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'event_id' => (string) $request->input('merchant_oid', ''),
            'subscription_id' => 0, // resolved from the checkout by CheckoutService
            'status' => $request->input('status') === 'success' ? 'succeeded' : 'failed',
            'amount' => null,
            'reference' => (string) $request->input('merchant_oid', ''),
            'total_amount_minor' => (int) $request->input('total_amount', 0),
            'failure' => trim($request->input('failed_reason_code', '').' '.$request->input('failed_reason_msg', '')) ?: null,
            'test_mode' => (string) $request->input('test_mode', '0') === '1',
        ];
    }

    public function acknowledge(): Response
    {
        return response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    private function sign(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, (string) $this->config['merchant_key'], true));
    }

    private function assertConfigured(): void
    {
        foreach (['merchant_id', 'merchant_key', 'merchant_salt'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                Log::error("[paytr] missing config subscriptions.paytr.{$key}");
                throw new DomainRuleException('Kartla ödeme şu anda kullanılamıyor.', 'payment_unavailable');
            }
        }
    }
}
