<?php

namespace App\Http\Controllers\Web\Tenant;

use App\Enums\AppointmentStatus;
use App\Enums\BillingInterval;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PanelNotification;
use App\Models\Plan;
use App\Models\Review;
use App\Models\TenantMedia;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\HostedCheckoutGateway;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\SubscriptionService;
use App\Services\Booking\BookingSettings;
use App\Services\Catalog\TenantCatalogService;
use App\Services\Reports\ReportService;
use App\Services\Reviews\ReviewService;
use App\Services\Settings\SettingsRepository;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PanelController extends PanelBaseController
{
    public function selectTenant(Request $request): View
    {
        return view('tenant.select', ['tenants' => $request->user()->activeTenants()->get()]);
    }

    public function switchTenant(Request $request): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        abort_unless($request->user()->isMemberOf((int) $data['tenant_id']), 403);
        $request->session()->put(config('tenancy.session_key'), (int) $data['tenant_id']);

        return redirect()->route('tenant.dashboard');
    }

    public function dashboard(Request $request, EntitlementService $entitlements): View
    {
        $tenant = $this->tenant();
        [$from, $to] = LocalTime::dayBoundsUtc(CarbonImmutable::now($tenant->timezone)->toDateString(), $tenant->timezone);
        $base = fn () => $this->scope()->scopeAppointments(Appointment::query(), $request->user(), $tenant);

        return view('tenant.dashboard', [
            'today' => $base()->with(['items', 'employee', 'customer', 'branch'])->where('starts_at_utc', '>=', $from)->where('starts_at_utc', '<', $to)
                ->whereIn('status', AppointmentStatus::active())->orderBy('starts_at_utc')->get(),
            'pending' => $base()->with(['items', 'employee', 'customer', 'branch'])->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::AlternativeProposed->value])->orderBy('starts_at_utc')->limit(20)->get(),
            'counts' => [
                'today' => $base()->where('starts_at_utc', '>=', $from)->where('starts_at_utc', '<', $to)->whereIn('status', AppointmentStatus::active())->count(),
                'pending' => $base()->where('status', AppointmentStatus::Pending->value)->count(),
                'week' => $base()->where('starts_at_utc', '>=', now())->where('starts_at_utc', '<', now()->addDays(7))->where('status', AppointmentStatus::Confirmed->value)->count(),
            ],
            'usage' => $entitlements->usage($tenant),
            'overLimit' => $entitlements->overLimit($tenant),
            'notifications' => PanelNotification::whereNull('read_at')->latest()->limit(5)->get(),
            'subscription' => $entitlements->subscription($tenant),
        ]);
    }

    public function calendar(Request $request): View
    {
        $tenant = $this->tenant();
        $branches = $this->scope()->scopeBranches(Branch::query(), $request->user(), $tenant, 'id')->where('status', 'active')->orderBy('name')->get();
        $branchId = (int) ($request->query('sube') ?: $branches->first()?->id);
        abort_if($branchId && ! $branches->contains('id', $branchId), 403);
        $employees = Employee::whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))->where('status', 'active')->orderBy('display_name')->get();
        $restricted = $this->scope()->restrictedEmployeeId($request->user(), $tenant);
        if ($restricted !== null) {
            $employees = $employees->where('id', $restricted)->values();
        }

        return view('tenant.calendar', compact('branches', 'branchId', 'employees') + [
            'timezone' => $branches->firstWhere('id', $branchId)?->timezone ?? $tenant->timezone,
            'canMove' => $this->allows($request, 'appointments.reschedule'),
        ]);
    }

    public function notifications(): View
    {
        return view('tenant.notifications', ['notifications' => PanelNotification::latest()->paginate(30)]);
    }

    public function markNotificationsRead(): RedirectResponse
    {
        PanelNotification::whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function reviews(Request $request): View
    {
        $this->need($request, 'reviews.reply');
        $reviews = $this->scope()->scopeBranches(Review::query()->with(['customer', 'reply', 'employee', 'branch']), $request->user(), $this->tenant())->latest()->paginate(20);

        return view('tenant.reviews', compact('reviews'));
    }

    public function replyReview(Request $request, Review $review, ReviewService $service): RedirectResponse
    {
        $this->authorize('reply', $review);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $service->reply($review, $request->user(), $data['body']);

        return back()->with('status', 'Yanıtınız kaydedildi.');
    }

    public function reportReview(Request $request, Review $review, ReviewService $service): RedirectResponse
    {
        $this->authorize('report', $review);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $service->report($review, $request->user(), 'tenant', $data['reason']);

        return back()->with('status', 'Değerlendirme platform moderasyonuna bildirildi.');
    }

    public function reports(Request $request, ReportService $reports): View
    {
        $this->need($request, 'reports.view');
        $tz = $this->tenant()->timezone;
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $fromLocal = $data['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString();
        $toLocal = $data['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $summary = $reports->summary($this->tenant(), CarbonImmutable::parse($fromLocal, $tz)->utc(), CarbonImmutable::parse($toLocal, $tz)->addDay()->utc(),
            $this->scope()->branchIds($request->user(), $this->tenant()));

        return view('tenant.reports', compact('summary', 'fromLocal', 'toLocal'));
    }

    public function settings(Request $request, EntitlementService $entitlements): View
    {
        $this->need($request, 'settings.manage');

        return view('tenant.settings', [
            'tenant' => $this->tenant(), 'booking' => BookingSettings::for($this->tenant()),
            'media' => TenantMedia::orderBy('sort_order')->get(), 'customTheme' => $entitlements->hasFeature($this->tenant(), 'custom_theme'),
        ]);
    }

    public function saveSettings(Request $request, SettingsRepository $settings, AuditLogger $audit): RedirectResponse
    {
        $this->need($request, 'settings.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'public_listing_enabled' => ['nullable', 'boolean'],
            'approval_mode' => ['required', Rule::in(['manual', 'automatic'])],
            'slot_interval_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'min_notice_minutes' => ['required', 'integer', 'between:0,10080'],
            'max_horizon_days' => ['required', 'integer', 'between:1,365'],
            'cancellation_cutoff_minutes' => ['required', 'integer', 'between:0,10080'],
            'pending_ttl_minutes' => ['required', 'integer', 'between:5,2880'],
            'proposal_ttl_minutes' => ['required', 'integer', 'between:5,2880'],
            'reminder_hours_before' => ['required', 'integer', 'between:1,168'],
            'require_phone_verification' => ['nullable', 'boolean'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $tenant = $this->tenant();
        $tenant->forceFill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'public_listing_enabled' => (bool) ($data['public_listing_enabled'] ?? false)]);
        if (! empty($data['primary_color']) && app(EntitlementService::class)->hasFeature($tenant, 'custom_theme')) {
            $tenant->settings = array_merge($tenant->settings ?? [], ['theme' => ['primary_color' => $data['primary_color']]]);
        }
        $tenant->save();
        $booking = collect($data)->only(BookingSettings::KEYS)->all();
        $booking['require_phone_verification'] = (bool) ($data['require_phone_verification'] ?? false);
        $settings->setTenant($tenant, $booking);
        $audit->log('tenant.settings_updated', $tenant, [], $booking);

        return back()->with('status', 'Ayarlar kaydedildi.');
    }

    public function uploadMedia(Request $request, TenantCatalogService $catalog): RedirectResponse
    {
        $this->need($request, 'settings.manage');
        $data = $request->validate([
            'kind' => ['required', 'in:logo,cover,gallery'],
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:4096', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'],
            'alt_text' => ['nullable', 'string', 'max:200'],
        ]);
        $tenant = $this->tenant();
        $path = $catalog->storeImage($data['image'], "tenants/{$tenant->id}/media");
        if ($data['kind'] === 'gallery') {
            TenantMedia::create(['path' => $path, 'mime' => 'image/jpeg', 'size' => Storage::disk('public')->size($path), 'alt_text' => $data['alt_text'] ?? $tenant->name, 'visibility' => 'public', 'sort_order' => TenantMedia::count()]);
        } else {
            $tenant->forceFill([$data['kind'] === 'logo' ? 'logo_path' : 'cover_path' => $path])->save();
        }

        return back()->with('status', 'Görsel yüklendi.');
    }

    public function subscription(Request $request, EntitlementService $entitlements, PaymentGatewayInterface $gateway, CheckoutService $checkouts): View
    {
        $tenant = $this->tenant();
        $subscription = $entitlements->subscription($tenant)?->load(['plan.features', 'pendingPlan', 'invoices']);
        $cardPayment = $subscription && $gateway instanceof HostedCheckoutGateway && $checkouts->canPay($subscription);

        return view('tenant.subscription', [
            'subscription' => $subscription,
            'plans' => Plan::with('features')->where('active', true)->where('public', true)->orderBy('sort_order')->get(),
            'usage' => $entitlements->usage($tenant), 'overLimit' => $entitlements->overLimit($tenant),
            'cardPayment' => $cardPayment,
            'nextAmount' => $cardPayment ? $checkouts->nextChargeAmount($subscription) : null,
            'canManage' => $this->allows($request, 'subscriptions.manage'),
        ]);
    }

    public function changePlan(Request $request, EntitlementService $entitlements, SubscriptionService $subscriptions): RedirectResponse
    {
        $this->need($request, 'subscriptions.manage');
        $data = $request->validate(['plan' => ['required', Rule::exists('plans', 'slug')->where('active', true)], 'interval' => ['required', Rule::in(BillingInterval::values())]]);
        $sub = $entitlements->subscription($this->tenant()) ?? abort(404);
        $sub = $subscriptions->changePlan($sub->load('plan'), Plan::with('features')->where('slug', $data['plan'])->firstOrFail(), BillingInterval::from($data['interval']), $request->user()->id);

        return back()->with('status', $sub->pending_plan_id ? 'Paket değişikliği bir sonraki dönem başında uygulanacak.' : 'Paketiniz güncellendi.');
    }
}
