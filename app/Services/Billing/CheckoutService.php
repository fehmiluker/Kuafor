<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Card checkout for a subscription period through a hosted provider (PayTR). */
final class CheckoutService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function canPay(Subscription $sub): bool
    {
        return $sub->status !== SubscriptionStatus::Cancelled || $sub->cancel_at_period_end;
    }

    /** Price of the next period: a scheduled plan change applies at renewal (SubscriptionService::recordPayment). */
    public function nextChargeAmount(Subscription $sub): Money
    {
        if ($sub->pending_plan_id) {
            $plan = Plan::findOrFail($sub->pending_plan_id);

            return $plan->priceFor(BillingInterval::from($sub->pending_billing_interval ?? $sub->billing_interval->value));
        }

        return $sub->price_snapshot;
    }

    public function start(HostedCheckoutGateway $gateway, Subscription $sub, User $user, string $ip, string $okUrl, string $failUrl): SubscriptionCheckout
    {
        if (! $this->canPay($sub)) {
            throw new DomainRuleException('İptal edilmiş abonelik için ödeme alınamaz.');
        }
        if (blank($user->email)) {
            throw new DomainRuleException('Kartla ödeme için hesabınızda bir e-posta adresi olmalı.', 'email_required');
        }
        $amount = $this->nextChargeAmount($sub);
        if ($amount->isZero() || $amount->isNegative()) {
            throw new DomainRuleException('Bu paket için ödeme gerekmiyor.');
        }

        $sub->loadMissing(['tenant', 'plan']);
        $plan = $sub->pending_plan_id ? Plan::findOrFail($sub->pending_plan_id) : $sub->plan;
        $interval = BillingInterval::from($sub->pending_billing_interval ?? $sub->billing_interval->value);
        $branch = $sub->tenant->branches()->whereNotNull('address')->orderBy('id')->first();

        $checkout = SubscriptionCheckout::create([
            'subscription_id' => $sub->id, 'tenant_id' => $sub->tenant_id, 'user_id' => $user->id,
            'provider' => $gateway->name(),
            // PayTR: alphanumeric only, unique per payment attempt.
            'merchant_oid' => 'KF'.$sub->id.'X'.Str::upper(Str::random(16)),
            'amount' => $amount, 'currency' => $amount->currency, 'status' => 'pending',
            'expires_at' => CarbonImmutable::now()->addMinutes($gateway->sessionMinutes()),
        ]);

        try {
            $token = $this->requestSession($gateway, $checkout, $sub, $user, $branch, $plan, $interval, $ip, $okUrl, $failUrl);
        } catch (DomainRuleException $e) {
            $checkout->forceFill(['status' => 'failed', 'failure_reason' => 'session_refused'])->save();

            throw $e;
        }
        $checkout->forceFill(['provider_token' => $token])->save();

        return $checkout;
    }

    private function requestSession(HostedCheckoutGateway $gateway, SubscriptionCheckout $checkout, Subscription $sub, User $user, ?Branch $branch, Plan $plan, BillingInterval $interval, string $ip, string $okUrl, string $failUrl): string
    {
        return $gateway->createSession($checkout, [
            'email' => $user->email,
            'name' => $user->name ?: $sub->tenant->name,
            'address' => $branch?->address ?: $sub->tenant->name.', Türkiye',
            'phone' => $user->phone_e164 ?: ($branch?->phone ?: '0000000000'),
            'ip' => $ip,
            'description' => config('app.name').' - '.$plan->name.' ('.$interval->label().')',
            'ok_url' => $okUrl,
            'fail_url' => $failUrl,
        ]);
    }

    /**
     * Apply a verified provider callback. Idempotent: PayTR may send the same
     * notification several times until it receives "OK".
     *
     * @param  array{event_id: string, status: string, total_amount_minor?: int, failure?: ?string}  $event
     */
    public function complete(HostedCheckoutGateway $gateway, array $event): void
    {
        DB::transaction(function () use ($gateway, $event) {
            $checkout = SubscriptionCheckout::where('provider', $gateway->name())
                ->where('merchant_oid', $event['event_id'])->lockForUpdate()->first();
            if (! $checkout) {
                Log::warning('['.$gateway->name().'] callback for unknown checkout', ['oid' => $event['event_id']]);

                return;
            }
            if ($checkout->status === 'succeeded') {
                return;
            }

            if ($event['status'] !== 'succeeded') {
                $checkout->forceFill(['status' => 'failed', 'failure_reason' => mb_substr((string) ($event['failure'] ?? 'failed'), 0, 255)])->save();

                return;
            }
            // Never trust a lower amount than the one we asked for (total may include installment interest).
            if (($event['total_amount_minor'] ?? 0) < $checkout->amount->minor) {
                Log::error('['.$gateway->name().'] paid amount lower than checkout amount', ['oid' => $checkout->merchant_oid, 'paid' => $event['total_amount_minor'] ?? null, 'expected' => $checkout->amount->minor]);
                $checkout->forceFill(['status' => 'failed', 'failure_reason' => 'amount_mismatch'])->save();

                return;
            }

            $payment = $this->subscriptions->recordPayment(
                $checkout->subscription, $gateway->name().':'.$checkout->merchant_oid, $checkout->amount,
                $gateway->name(), $checkout->merchant_oid, $checkout->merchant_oid, $checkout->user_id,
            );
            $checkout->forceFill(['status' => 'succeeded', 'payment_id' => $payment->id, 'paid_at' => now(), 'failure_reason' => null])->save();
        });
    }
}
