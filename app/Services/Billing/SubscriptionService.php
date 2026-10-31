<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Subscription lifecycle:
 *   trialing -> active (payment) -> past_due (period ended unpaid) -> grace_period -> suspended
 *   any -> cancelled (immediately or at period end) ; trialing/active -> expired (trial over, no payment)
 *
 * Entitlements are snapshotted at period start. Upgrades apply immediately
 * (new snapshot), downgrades at the next period start; nothing is ever deleted
 * on downgrade — over-limit branches/employees must be deactivated by the tenant.
 */
final class SubscriptionService
{
    public function startTrial(Tenant $tenant, Plan $plan, BillingInterval $interval = BillingInterval::Monthly, ?int $actorId = null): Subscription
    {
        $plan->loadMissing('features');
        $now = CarbonImmutable::now();
        $sub = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $plan->trial_days > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::PastDue,
            'billing_interval' => $interval,
            'entitlements_snapshot' => $plan->entitlements(),
            'price_snapshot' => $plan->priceFor($interval),
            'currency' => $plan->currency,
            'trial_ends_at' => $plan->trial_days > 0 ? $now->addDays($plan->trial_days) : null,
            'current_period_start' => $now,
            'current_period_end' => $plan->trial_days > 0 ? $now->addDays($plan->trial_days) : $now,
            'provider' => config('subscriptions.provider'),
        ]);
        $this->event($sub, 'trial_started', null, $sub->status, $actorId);

        return $sub;
    }

    /** Activate (or renew) one billing period after a successful payment. Idempotent per idempotency key. */
    public function recordPayment(Subscription $sub, string $idempotencyKey, ?Money $amount = null, string $provider = 'manual', ?string $providerRef = null, ?string $providerEventId = null, ?int $actorId = null): SubscriptionPayment
    {
        return DB::transaction(function () use ($sub, $idempotencyKey, $amount, $provider, $providerRef, $providerEventId, $actorId) {
            $existing = SubscriptionPayment::where('idempotency_key', $idempotencyKey)
                ->when($providerEventId, fn ($q) => $q->orWhere(fn ($w) => $w->where('provider', $provider)->where('provider_event_id', $providerEventId)))
                ->first();
            if ($existing) {
                return $existing;
            }
            /** @var Subscription $sub */
            $sub = Subscription::whereKey($sub->id)->lockForUpdate()->firstOrFail();
            if (in_array($sub->status, [SubscriptionStatus::Cancelled], true) && ! $sub->cancel_at_period_end) {
                throw new DomainRuleException('İptal edilmiş abonelik için ödeme alınamaz.');
            }
            // Apply a scheduled downgrade/plan change at the renewal boundary.
            if ($sub->pending_plan_id) {
                $plan = Plan::with('features')->findOrFail($sub->pending_plan_id);
                $interval = BillingInterval::from($sub->pending_billing_interval ?? $sub->billing_interval->value);
                $sub->forceFill([
                    'plan_id' => $plan->id, 'billing_interval' => $interval, 'price_snapshot' => $plan->priceFor($interval),
                    'pending_plan_id' => null, 'pending_billing_interval' => null,
                ]);
            }
            $plan = Plan::with('features')->findOrFail($sub->plan_id);
            $now = CarbonImmutable::now();
            $start = ($sub->status === SubscriptionStatus::Active && $sub->current_period_end && $sub->current_period_end->isFuture())
                ? $sub->current_period_end : $now;
            $end = $sub->billing_interval === BillingInterval::Yearly ? $start->addYear() : $start->addMonth();
            $amount ??= $sub->price_snapshot;

            $invoice = $this->issueInvoice($sub, $amount, $start, $end);
            $invoice->forceFill(['status' => 'paid', 'paid_at' => $now])->save();

            $payment = SubscriptionPayment::create([
                'subscription_id' => $sub->id, 'invoice_id' => $invoice->id, 'tenant_id' => $sub->tenant_id,
                'amount' => $amount, 'currency' => $sub->currency, 'period_start' => $start, 'period_end' => $end,
                'status' => 'succeeded', 'provider' => $provider, 'provider_reference' => $providerRef,
                'provider_event_id' => $providerEventId, 'idempotency_key' => $idempotencyKey, 'paid_at' => $now,
            ]);
            $from = $sub->status;
            $sub->forceFill([
                'status' => SubscriptionStatus::Active,
                'entitlements_snapshot' => $plan->entitlements(),
                'current_period_start' => $start,
                'current_period_end' => $end,
                'grace_ends_at' => null,
                'trial_ends_at' => $sub->trial_ends_at,
            ])->save();
            $this->event($sub, 'payment_succeeded', $from, SubscriptionStatus::Active, $actorId, ['payment_id' => $payment->id]);

            return $payment;
        });
    }

    /** Upgrade = immediate (snapshot refreshed); downgrade = at period end. */
    public function changePlan(Subscription $sub, Plan $plan, BillingInterval $interval, ?int $actorId = null): Subscription
    {
        $plan->loadMissing('features');
        $current = $sub->plan;
        $isUpgrade = $plan->max_branches >= $current->max_branches && $plan->max_employees >= $current->max_employees
            && $plan->priceFor($interval)->minor >= $current->priceFor($sub->billing_interval)->minor;

        if ($isUpgrade || $sub->status === SubscriptionStatus::Trialing) {
            $sub->forceFill([
                'plan_id' => $plan->id, 'billing_interval' => $interval, 'price_snapshot' => $plan->priceFor($interval),
                'entitlements_snapshot' => $plan->entitlements(), 'pending_plan_id' => null, 'pending_billing_interval' => null,
            ])->save();
            $this->event($sub, 'plan_changed', $sub->status, $sub->status, $actorId, ['plan' => $plan->slug, 'immediate' => true]);
        } else {
            $sub->forceFill(['pending_plan_id' => $plan->id, 'pending_billing_interval' => $interval->value])->save();
            $this->event($sub, 'plan_change_scheduled', $sub->status, $sub->status, $actorId, ['plan' => $plan->slug]);
        }

        return $sub->refresh();
    }

    /** Admin/manual override of the subscription status (logged). */
    public function setStatus(Subscription $sub, SubscriptionStatus $status, ?int $actorId = null, ?string $reason = null): Subscription
    {
        $from = $sub->status;
        $attrs = ['status' => $status];
        if ($status === SubscriptionStatus::Cancelled) {
            $attrs['cancelled_at'] = now();
        }
        if ($status === SubscriptionStatus::GracePeriod) {
            $attrs['grace_ends_at'] = now()->addDays((int) config('subscriptions.grace_period_days'));
        }
        $sub->forceFill($attrs)->save();
        $this->event($sub, 'status_changed', $from, $status, $actorId, ['reason' => $reason]);

        return $sub;
    }

    public function cancel(Subscription $sub, bool $atPeriodEnd = true, ?int $actorId = null): Subscription
    {
        if ($atPeriodEnd) {
            $sub->forceFill(['cancel_at_period_end' => true])->save();
            $this->event($sub, 'cancel_scheduled', $sub->status, $sub->status, $actorId);

            return $sub;
        }

        return $this->setStatus($sub, SubscriptionStatus::Cancelled, $actorId);
    }

    /**
     * Daily sweep (idempotent): trial end, period end -> past_due -> grace -> suspended.
     *
     * @return array<string, int>
     */
    public function sweep(): array
    {
        $now = CarbonImmutable::now();
        $stats = ['expired' => 0, 'past_due' => 0, 'grace' => 0, 'suspended' => 0, 'cancelled' => 0];
        Subscription::whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::GracePeriod->value])
            ->chunkById(200, function ($subs) use ($now, &$stats) {
                foreach ($subs as $sub) {
                    if ($sub->cancel_at_period_end && $sub->current_period_end?->lte($now)) {
                        $this->setStatus($sub, SubscriptionStatus::Cancelled, null, 'period_end');
                        $stats['cancelled']++;
                    } elseif ($sub->status === SubscriptionStatus::Trialing && $sub->trial_ends_at?->lte($now)) {
                        $this->setStatus($sub, SubscriptionStatus::Expired, null, 'trial_ended');
                        $stats['expired']++;
                    } elseif ($sub->status === SubscriptionStatus::Active && $sub->current_period_end?->lte($now)) {
                        $this->setStatus($sub, SubscriptionStatus::PastDue, null, 'period_ended_unpaid');
                        $stats['past_due']++;
                    } elseif ($sub->status === SubscriptionStatus::PastDue && $sub->current_period_end?->addDays((int) config('subscriptions.past_due_days'))->lte($now)) {
                        $this->setStatus($sub, SubscriptionStatus::GracePeriod, null, 'past_due_elapsed');
                        $stats['grace']++;
                    } elseif ($sub->status === SubscriptionStatus::GracePeriod && $sub->grace_ends_at?->lte($now)) {
                        $this->setStatus($sub, SubscriptionStatus::Suspended, null, 'grace_elapsed');
                        $stats['suspended']++;
                    }
                }
            });

        return $stats;
    }

    public function issueInvoice(Subscription $sub, Money $amount, CarbonImmutable $start, CarbonImmutable $end): SubscriptionInvoice
    {
        $existing = SubscriptionInvoice::where('subscription_id', $sub->id)->where('period_start', $start)->first();
        if ($existing) {
            return $existing;
        }
        // Prices are tax-inclusive; tax portion shown separately for reporting.
        $rate = (int) config('subscriptions.tax_rate_percent');
        $tax = Money::ofMinor(intdiv($amount->minor * $rate + intdiv(100 + $rate, 2), 100 + $rate), $amount->currency);

        return SubscriptionInvoice::create([
            'subscription_id' => $sub->id, 'tenant_id' => $sub->tenant_id,
            'number' => config('subscriptions.invoice_prefix').'-'.$start->format('Ym').'-'.str_pad((string) $sub->id, 5, '0', STR_PAD_LEFT).'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)),
            'amount' => $amount, 'tax_amount' => $tax, 'currency' => $amount->currency,
            'period_start' => $start, 'period_end' => $end, 'status' => 'open', 'due_at' => $start->addDays(7),
        ]);
    }

    private function event(Subscription $sub, string $type, ?SubscriptionStatus $from, ?SubscriptionStatus $to, ?int $actorId, array $data = []): void
    {
        SubscriptionEvent::create([
            'subscription_id' => $sub->id, 'tenant_id' => $sub->tenant_id, 'type' => $type,
            'from_status' => $from?->value, 'to_status' => $to?->value, 'actor_user_id' => $actorId, 'data' => $data ?: null,
        ]);
    }
}
