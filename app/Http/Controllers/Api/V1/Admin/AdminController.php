<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BillingInterval;
use App\Enums\ReviewStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Admin\PlatformMetrics;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Services\Reviews\ModerationService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Platform administration. Every mutation is audit-logged with the actor. */
class AdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPlatformPermission($permission), 403);
    }

    public function dashboard(Request $request, PlatformMetrics $metrics): JsonResponse
    {
        $this->need($request, 'tenants.view');

        return ApiResponse::ok($metrics->dashboard());
    }

    // ------------------------------------------------------------ tenants

    public function tenants(Request $request): JsonResponse
    {
        $this->need($request, 'tenants.view');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(TenantStatus::values())]]);
        $page = Tenant::query()->withCount(['branches', 'employees'])->with('subscription.plan')
            ->when($data['q'] ?? null, fn ($q, $t) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%'))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(25);

        return ApiResponse::ok($page->getCollection()->map(fn ($t) => $this->tenantPayload($t))->all(), ['pagination' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]]);
    }

    public function storeTenant(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $this->need($request, 'tenants.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'owner_email' => ['required', 'email', 'exists:users,email'],
            'plan' => ['required', 'exists:plans,slug'],
        ]);
        $tenant = $provisioner->provision(User::where('email', $data['owner_email'])->firstOrFail(), $data['name'], Plan::with('features')->where('slug', $data['plan'])->firstOrFail());
        $this->audit->log('admin.tenant_created', $tenant, [], ['name' => $tenant->name], tenantId: $tenant->id);

        return ApiResponse::created($this->tenantPayload($tenant->loadCount(['branches', 'employees'])->load('subscription.plan')));
    }

    public function showTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $this->need($request, 'tenants.view');

        return ApiResponse::ok($this->tenantPayload($tenant->loadCount(['branches', 'employees'])->load('subscription.plan')));
    }

    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $this->need($request, 'tenants.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'status' => ['sometimes', Rule::in(TenantStatus::values())],
            'reason' => ['required_with:status', 'nullable', 'string', 'min:5', 'max:500'],
            'public_listing_enabled' => ['sometimes', 'boolean'],
        ]);
        $before = $tenant->only(['name', 'status', 'public_listing_enabled']);
        $attrs = collect($data)->only(['name', 'public_listing_enabled'])->all();
        if (isset($data['status'])) {
            $attrs['status'] = $data['status'];
            $attrs['suspended_at'] = $data['status'] === TenantStatus::Suspended->value ? now() : null;
            $attrs['suspension_reason'] = $data['status'] === TenantStatus::Suspended->value ? $data['reason'] : null;
        }
        $tenant->forceFill($attrs)->save();
        $this->audit->log('admin.tenant_updated', $tenant, $before, $tenant->only(['name', 'status', 'public_listing_enabled']), $data['reason'] ?? null, tenantId: $tenant->id);

        return $this->showTenant($request, $tenant);
    }

    /** Tenants are never hard-deleted from the API: they are closed (data retained per policy). */
    public function destroyTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $this->need($request, 'tenants.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $tenant->forceFill(['status' => TenantStatus::Closed, 'public_listing_enabled' => false])->save();
        $this->audit->log('admin.tenant_closed', $tenant, [], [], $data['reason'], tenantId: $tenant->id);

        return ApiResponse::ok(['closed' => true]);
    }

    /** Reasoned, limited, logged PII access (e.g. legal request, abuse investigation). */
    public function tenantCustomers(Request $request, Tenant $tenant): JsonResponse
    {
        $this->need($request, 'pii.access');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'phone' => ['nullable', 'string']]);
        $q = TenantCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->when($data['phone'] ?? null, fn ($q, $p) => $q->where('phone_e164', PhoneNumber::normalize($p)));
        $rows = $q->limit(50)->get(['id', 'full_name', 'phone_e164', 'email', 'created_at']);
        $this->audit->log('admin.pii_access', $tenant, [], ['rows' => $rows->count()], $data['reason'], tenantId: $tenant->id);

        return ApiResponse::ok($rows, ['limited_to' => 50]);
    }

    // ------------------------------------------------------------ plans

    public function plans(Request $request): JsonResponse
    {
        $this->need($request, 'tenants.view');

        return ApiResponse::ok(PlanResource::collection(Plan::with('features')->orderBy('sort_order')->get()));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $this->need($request, 'plans.manage');
        $plan = DB::transaction(fn () => $this->savePlan(new Plan, $this->planRules($request)));

        return ApiResponse::created(new PlanResource($plan->load('features')));
    }

    public function showPlan(Request $request, Plan $plan): JsonResponse
    {
        $this->need($request, 'tenants.view');

        return ApiResponse::ok(new PlanResource($plan->load('features')));
    }

    /** Editing a plan affects NEW periods only — existing subscriptions keep their snapshot. */
    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $this->need($request, 'plans.manage');
        $plan = DB::transaction(fn () => $this->savePlan($plan, $this->planRules($request, $plan)));

        return ApiResponse::ok(new PlanResource($plan->load('features')));
    }

    public function destroyPlan(Request $request, Plan $plan): JsonResponse
    {
        $this->need($request, 'plans.manage');
        $plan->forceFill(['active' => false, 'public' => false])->save();
        $plan->delete();
        $this->audit->log('admin.plan_archived', $plan);

        return ApiResponse::ok(['archived' => true]);
    }

    private function planRules(Request $request, ?Plan $plan = null): array
    {
        $req = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'slug' => [$req, 'alpha_dash', 'max:80', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => [$req, 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'yearly_price' => [$req, 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'trial_days' => ['sometimes', 'integer', 'between:0,90'],
            'max_branches' => [$req, 'integer', 'between:1,1000'],
            'max_employees' => [$req, 'integer', 'between:1,10000'],
            'active' => ['sometimes', 'boolean'], 'public' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ]);
    }

    private function savePlan(Plan $plan, array $data): Plan
    {
        $before = $plan->exists ? $plan->only(['monthly_price', 'yearly_price', 'max_branches', 'max_employees']) : [];
        $plan->fill(collect($data)->except('features')->all())->save();
        foreach ($data['features'] ?? [] as $key => $enabled) {
            if (array_key_exists($key, config('subscriptions.features'))) {
                $plan->features()->updateOrCreate(['feature_key' => $key], ['enabled' => (bool) $enabled]);
            }
        }
        $this->audit->log($plan->wasRecentlyCreated ? 'admin.plan_created' : 'admin.plan_updated', $plan, array_map('strval', $before), array_map('strval', $plan->only(['monthly_price', 'yearly_price', 'max_branches', 'max_employees'])));

        return $plan;
    }

    // ------------------------------------------------------------ subscriptions

    public function showSubscription(Request $request, Subscription $subscription): JsonResponse
    {
        $this->need($request, 'tenants.view');

        return ApiResponse::ok(new SubscriptionResource($subscription->load(['plan.features', 'pendingPlan'])), [
            'events' => $subscription->events()->limit(50)->get(),
            'invoices' => $subscription->invoices()->limit(24)->get()->map(fn ($i) => [
                'number' => $i->number, 'amount' => $i->amount->jsonSerialize(), 'status' => $i->status,
                'period_start' => $i->period_start->toIso8601ZuluString(), 'period_end' => $i->period_end->toIso8601ZuluString(),
            ]),
        ]);
    }

    public function updateSubscription(Request $request, Subscription $subscription, SubscriptionService $subscriptions): JsonResponse
    {
        $this->need($request, 'platform.subscriptions.manage');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SubscriptionStatus::values())],
            'record_payment' => ['sometimes', 'boolean'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'plan' => ['sometimes', Rule::exists('plans', 'slug')],
            'interval' => ['required_with:plan', Rule::in(['monthly', 'yearly'])],
            'trial_ends_at' => ['sometimes', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $actor = $request->user()->id;
        if (isset($data['plan'])) {
            $subscriptions->changePlan($subscription->load('plan'), Plan::with('features')->where('slug', $data['plan'])->firstOrFail(), BillingInterval::from($data['interval']), $actor);
        }
        if (! empty($data['record_payment'])) {
            // Manual activation (bank transfer etc.). Key = admin-supplied reference, so repeats are idempotent.
            $subscriptions->recordPayment($subscription->refresh(), 'manual:'.($data['payment_reference'] ?? $subscription->id.':'.now()->format('Ym')), null, 'manual', $data['payment_reference'] ?? null, null, $actor);
        }
        if (isset($data['trial_ends_at'])) {
            $subscription->refresh()->forceFill(['trial_ends_at' => $data['trial_ends_at'], 'current_period_end' => $data['trial_ends_at']])->save();
        }
        if (isset($data['status'])) {
            $subscriptions->setStatus($subscription->refresh(), SubscriptionStatus::from($data['status']), $actor, $data['reason']);
        }
        $this->audit->log('admin.subscription_updated', $subscription, [], collect($data)->except('reason')->all(), $data['reason'], tenantId: $subscription->tenant_id);

        return $this->showSubscription($request, $subscription->refresh());
    }

    // ------------------------------------------------------------ whatsapp / moderation / audit

    public function whatsappHealth(Request $request): JsonResponse
    {
        $this->need($request, 'tenants.view');
        $connections = WhatsAppConnection::withoutGlobalScopes()->get()->groupBy(fn ($c) => $c->status->value)->map->count();

        return ApiResponse::ok([
            'mode' => config('whatsapp.mode'),
            'connections' => $connections,
            'webhooks' => DB::table('whatsapp_webhook_events')->where('received_at', '>=', now()->subDay())->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status'),
            'messages_24h' => DB::table('whatsapp_messages')->where('created_at', '>=', now()->subDay())->groupBy('status')->selectRaw('status, COUNT(*) c')->pluck('c', 'status'),
            'queue_backlog' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'pricing_config' => config('whatsapp.pricing'),
        ]);
    }

    public function reviewReports(Request $request): JsonResponse
    {
        $this->need($request, 'reviews.moderate');
        $page = ReviewReport::with(['review' => fn ($q) => $q->withoutGlobalScopes()])->where('status', $request->query('status', 'open'))->latest()->paginate(25);

        return ApiResponse::ok($page->getCollection()->map(fn ($r) => [
            'id' => $r->id, 'review_id' => $r->review_id, 'reason' => $r->reason, 'status' => $r->status, 'reporter_type' => $r->reporter_type,
            'is_appeal' => $r->is_appeal, 'review' => $r->review ? ['rating' => $r->review->rating, 'body' => $r->review->body, 'status' => $r->review->status->value, 'tenant_id' => $r->review->tenant_id] : null,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
        ])->all(), ['pagination' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function moderate(Request $request, int $review, ModerationService $moderation): JsonResponse
    {
        $this->need($request, 'reviews.moderate');
        $data = $request->validate(['status' => ['required', Rule::in(ReviewStatus::values())], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'report_id' => ['nullable', 'integer']]);
        $model = Review::withoutGlobalScopes()->findOrFail($review);
        $report = isset($data['report_id']) ? ReviewReport::where('review_id', $model->id)->findOrFail($data['report_id']) : null;
        $model = $moderation->moderate($model, $request->user(), ReviewStatus::from($data['status']), $data['reason'], $report);

        return ApiResponse::ok(['id' => $model->id, 'status' => $model->status->value]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->need($request, 'audit.view');
        $data = $request->validate(['tenant_id' => ['nullable', 'integer'], 'action' => ['nullable', 'string', 'max:80']]);
        $page = AuditLog::query()->when($data['tenant_id'] ?? null, fn ($q, $t) => $q->where('tenant_id', $t))
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))->latest('id')->paginate(50);

        return ApiResponse::ok($page->items(), ['pagination' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    private function tenantPayload(Tenant $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status->value,
            'public_listing_enabled' => $t->public_listing_enabled, 'branches' => $t->branches_count ?? null, 'employees' => $t->employees_count ?? null,
            'subscription' => $t->subscription ? ['id' => $t->subscription->id, 'status' => $t->subscription->status->value, 'plan' => $t->subscription->plan?->slug] : null,
            'rating' => $t->rating_avg !== null ? (float) $t->rating_avg : null, 'created_at' => $t->created_at?->toIso8601ZuluString(),
            'suspension_reason' => $t->suspension_reason,
        ];
    }
}
