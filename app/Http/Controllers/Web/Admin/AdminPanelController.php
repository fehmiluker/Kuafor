<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\BillingInterval;
use App\Enums\ReviewStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Services\Admin\PlatformMetrics;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Services\Reviews\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminPanelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPlatformPermission($permission), 403);
    }

    public function dashboard(Request $request, PlatformMetrics $metrics): View
    {
        $this->need($request, 'tenants.view');

        return view('admin.dashboard', ['m' => $metrics->dashboard()]);
    }

    public function tenants(Request $request): View
    {
        $this->need($request, 'tenants.view');
        $q = trim((string) $request->query('q'));
        $tenants = Tenant::withCount(['branches', 'employees'])->with('subscription.plan')
            ->when($q !== '', fn ($w) => $w->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
            ->when($request->query('durum'), fn ($w, $s) => $w->where('status', $s))->latest()->paginate(25)->withQueryString();

        return view('admin.tenants', compact('tenants', 'q'));
    }

    public function tenant(Request $request, Tenant $tenant): View
    {
        $this->need($request, 'tenants.view');
        $tenant->loadCount(['branches', 'employees'])->load(['owner', 'subscription.plan', 'subscription.events', 'subscription.invoices']);

        return view('admin.tenant', [
            'tenant' => $tenant, 'plans' => Plan::orderBy('sort_order')->get(),
            'audit' => AuditLog::where('tenant_id', $tenant->id)->latest('id')->limit(20)->get(),
            'appointments30d' => DB::table('appointments')->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays(30))->count(),
            'canManage' => $request->user()->hasPlatformPermission('tenants.manage'),
        ]);
    }

    public function updateTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->need($request, 'tenants.manage');
        $d = $request->validate(['status' => ['required', Rule::in(TenantStatus::values())], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'public_listing_enabled' => ['nullable', 'boolean']]);
        $before = $tenant->only(['status', 'public_listing_enabled']);
        $tenant->forceFill([
            'status' => $d['status'], 'public_listing_enabled' => (bool) ($d['public_listing_enabled'] ?? false),
            'suspended_at' => $d['status'] === 'suspended' ? now() : null, 'suspension_reason' => $d['status'] === 'suspended' ? $d['reason'] : null,
        ])->save();
        $this->audit->log('admin.tenant_updated', $tenant, $before, $tenant->only(['status', 'public_listing_enabled']), $d['reason'], tenantId: $tenant->id);

        return back()->with('status', 'İşletme güncellendi.');
    }

    public function updateSubscription(Request $request, Subscription $subscription, SubscriptionService $subscriptions): RedirectResponse
    {
        $this->need($request, 'platform.subscriptions.manage');
        $d = $request->validate([
            'action' => ['required', Rule::in(['record_payment', 'set_status', 'change_plan', 'extend_trial'])],
            'status' => ['required_if:action,set_status', 'nullable', Rule::in(SubscriptionStatus::values())],
            'plan' => ['required_if:action,change_plan', 'nullable', 'exists:plans,slug'],
            'interval' => ['nullable', Rule::in(BillingInterval::values())],
            'days' => ['required_if:action,extend_trial', 'nullable', 'integer', 'between:1,90'],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $actor = $request->user()->id;
        match ($d['action']) {
            'record_payment' => $subscriptions->recordPayment($subscription, 'manual:'.($d['reference'] ?: $subscription->id.':'.now()->format('YmdHi')), null, 'manual', $d['reference'] ?? null, null, $actor),
            'set_status' => $subscriptions->setStatus($subscription, SubscriptionStatus::from($d['status']), $actor, $d['reason']),
            'change_plan' => $subscriptions->changePlan($subscription->load('plan'), Plan::with('features')->where('slug', $d['plan'])->firstOrFail(), BillingInterval::from($d['interval'] ?? 'monthly'), $actor),
            'extend_trial' => $subscription->forceFill([
                'trial_ends_at' => ($subscription->trial_ends_at ?? now())->addDays((int) $d['days']),
                'current_period_end' => ($subscription->trial_ends_at ?? now())->addDays((int) $d['days']),
                'status' => SubscriptionStatus::Trialing,
            ])->save(),
        };
        $this->audit->log('admin.subscription_'.$d['action'], $subscription, [], collect($d)->except('reason')->all(), $d['reason'], tenantId: $subscription->tenant_id);

        return back()->with('status', 'Abonelik güncellendi.');
    }

    public function plans(Request $request): View
    {
        $this->need($request, 'tenants.view');

        return view('admin.plans', ['plans' => Plan::with('features')->withCount('features')->orderBy('sort_order')->get(), 'featureKeys' => config('subscriptions.features'),
            'canManage' => $request->user()->hasPlatformPermission('plans.manage')]);
    }

    public function savePlan(Request $request, ?Plan $plan = null): RedirectResponse
    {
        $this->need($request, 'plans.manage');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'], 'yearly_price' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'trial_days' => ['required', 'integer', 'between:0,90'], 'max_branches' => ['required', 'integer', 'between:1,1000'], 'max_employees' => ['required', 'integer', 'between:1,10000'],
            'active' => ['nullable', 'boolean'], 'public' => ['nullable', 'boolean'], 'features' => ['array'], 'features.*' => ['boolean'],
        ]);
        DB::transaction(function () use ($d, &$plan) {
            $plan ??= new Plan;
            $plan->fill(collect($d)->except('features')->all() + ['active' => (bool) ($d['active'] ?? false), 'public' => (bool) ($d['public'] ?? false)])->save();
            foreach (array_keys(config('subscriptions.features')) as $key) {
                $plan->features()->updateOrCreate(['feature_key' => $key], ['enabled' => (bool) ($d['features'][$key] ?? false)]);
            }
            $this->audit->log($plan->wasRecentlyCreated ? 'admin.plan_created' : 'admin.plan_updated', $plan, [], ['slug' => $plan->slug]);
        });

        return back()->with('status', 'Paket kaydedildi. Mevcut aboneler bir sonraki dönem başında yeni koşullara geçer.');
    }

    public function moderation(Request $request): View
    {
        $this->need($request, 'reviews.moderate');
        $reports = ReviewReport::with(['review' => fn ($q) => $q->withoutGlobalScopes(), 'reporter'])->where('status', $request->query('durum', 'open'))->latest()->paginate(20);
        $tenants = Tenant::whereIn('id', $reports->pluck('review.tenant_id')->filter())->pluck('name', 'id');

        return view('admin.moderation', compact('reports', 'tenants'));
    }

    public function moderate(Request $request, int $review, ModerationService $moderation): RedirectResponse
    {
        $this->need($request, 'reviews.moderate');
        $d = $request->validate(['status' => ['required', Rule::in(ReviewStatus::values())], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'report_id' => ['nullable', 'integer']]);
        $model = Review::withoutGlobalScopes()->findOrFail($review);
        $moderation->moderate($model, $request->user(), ReviewStatus::from($d['status']), $d['reason'], isset($d['report_id']) ? ReviewReport::find($d['report_id']) : null);

        return back()->with('status', 'Moderasyon kararı kaydedildi.');
    }

    public function audit(Request $request): View
    {
        $this->need($request, 'audit.view');
        $logs = AuditLog::query()->when($request->query('islem'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->query('isletme'), fn ($q, $t) => $q->where('tenant_id', $t))->latest('id')->paginate(50)->withQueryString();

        return view('admin.audit', compact('logs'));
    }

    public function system(Request $request): View
    {
        $this->need($request, 'tenants.view');

        return view('admin.system', [
            'mode' => config('whatsapp.mode'),
            'connections' => WhatsAppConnection::withoutGlobalScopes()->get()->groupBy(fn ($c) => $c->status->value)->map->count(),
            'webhooks' => DB::table('whatsapp_webhook_events')->where('received_at', '>=', now()->subDay())->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status'),
            'messages' => DB::table('whatsapp_messages')->where('created_at', '>=', now()->subDay())->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status'),
            'jobs' => DB::table('jobs')->count(), 'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['id', 'queue', 'failed_at', 'exception']),
            'pricing' => config('whatsapp.pricing'),
        ]);
    }
}
