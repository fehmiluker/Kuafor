<?php

namespace App\Services\Admin;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Platform KPIs.
 * MRR assumptions (shown in the UI): only `active` subscriptions count;
 * monthly = price_snapshot, yearly = price_snapshot / 12 (integer kuruş,
 * rounded down); trials, past_due, grace and suspended are excluded;
 * prices are tax-inclusive list prices (no discounts/refunds modelled).
 * ARR = MRR × 12.
 */
final class PlatformMetrics
{
    public function dashboard(): array
    {
        $latest = DB::table('subscriptions as s')->whereRaw('s.id = (select max(id) from subscriptions s2 where s2.tenant_id = s.tenant_id)');
        $byStatus = (clone $latest)->groupBy('s.status')->selectRaw('s.status, COUNT(*) c')->pluck('c', 'status')->all();

        $mrr = Money::zero();
        Subscription::query()->whereIn('id', (clone $latest)->select('s.id'))->where('status', SubscriptionStatus::Active->value)
            ->each(function (Subscription $s) use (&$mrr) {
                $mrr = $mrr->plus($s->billing_interval === BillingInterval::Yearly ? Money::ofMinor(intdiv($s->price_snapshot->minor, 12)) : $s->price_snapshot);
            });

        $messages = DB::table('whatsapp_messages')->where('direction', 'outbound')->where('created_at', '>=', now()->subDays(7))
            ->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status')->all();
        $sent = array_sum($messages);
        $ok = ($messages['sent'] ?? 0) + ($messages['delivered'] ?? 0) + ($messages['read'] ?? 0);

        return [
            'tenants' => ['total' => DB::table('tenants')->whereNull('deleted_at')->count(), 'by_status' => DB::table('tenants')->whereNull('deleted_at')->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status')->all()],
            'subscriptions' => $byStatus,
            'branches' => DB::table('branches')->whereNull('deleted_at')->count(),
            'users' => DB::table('users')->whereNull('deleted_at')->count(),
            'appointments' => [
                'last_30d' => DB::table('appointments')->where('created_at', '>=', now()->subDays(30))->count(),
                'today' => DB::table('appointments')->where('starts_at_utc', '>=', now()->startOfDay())->where('starts_at_utc', '<', now()->endOfDay())->count(),
            ],
            'mrr' => $mrr->jsonSerialize(),
            'arr' => $mrr->times(12)->jsonSerialize(),
            'mrr_assumptions' => 'Yalnızca aktif abonelikler; yıllık fiyat/12; deneme, gecikmiş, ek süre ve askıdakiler hariç; KDV dahil liste fiyatı.',
            'whatsapp_7d' => ['total' => $sent, 'success_rate' => $sent ? round($ok / $sent * 100, 1) : null, 'by_status' => $messages],
            'abuse' => [
                'open_review_reports' => DB::table('review_reports')->where('status', 'open')->count(),
                'open_abuse_reports' => DB::table('abuse_reports')->where('status', 'open')->count(),
                'failed_webhooks' => DB::table('whatsapp_webhook_events')->where('status', 'failed')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
        ];
    }
}
