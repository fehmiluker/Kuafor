<?php

namespace App\Services\Search;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Booking\AvailabilityService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Public salon search. Only tenants that are active, opted in to public listing
 * and have a usable subscription are returned. Filters: city, district,
 * category/service text, price range, availability on a date (+ optional time).
 */
final class SalonSearchService
{
    public const SORTS = ['rating', 'price_asc', 'price_desc', 'name', 'newest'];

    public function __construct(private readonly AvailabilityService $availability, private readonly TenantContext $context) {}

    public function search(array $f, int $perPage = 12): LengthAwarePaginator
    {
        return $this->context->asPlatform(function () use ($f, $perPage) {
            $q = Tenant::query()->publiclyListed()->select('tenants.*');

            $branchFilter = function (Builder|\Illuminate\Database\Query\Builder $b) use ($f) {
                $b->from('branches')->whereColumn('branches.tenant_id', 'tenants.id')
                    ->where('branches.status', RecordStatus::Active->value)->whereNull('branches.deleted_at');
                if (! empty($f['city'])) {
                    $b->whereIn('branches.city_id', DB::table('cities')->where('slug', $f['city'])->orWhere('id', (int) $f['city'])->select('id'));
                }
                if (! empty($f['district'])) {
                    $b->whereIn('branches.district_id', DB::table('districts')->where('slug', $f['district'])->orWhere('id', (int) $f['district'])->select('id'));
                }
            };
            $q->whereExists($branchFilter);

            if (! empty($f['category']) || ! empty($f['service']) || isset($f['min_price']) || isset($f['max_price'])) {
                $q->whereExists(function ($s) use ($f) {
                    $s->from('services')->whereColumn('services.tenant_id', 'tenants.id')
                        ->where('services.active', true)->whereNull('services.deleted_at');
                    if (! empty($f['category'])) {
                        $s->whereIn('services.category_id', DB::table('service_categories')->where('slug', $f['category'])->select('id'));
                    }
                    if (! empty($f['service'])) {
                        $s->where('services.name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $f['service']).'%');
                    }
                    if (isset($f['min_price']) && $f['min_price'] !== '') {
                        $s->where('services.base_price', '>=', (string) $f['min_price']);
                    }
                    if (isset($f['max_price']) && $f['max_price'] !== '') {
                        $s->where('services.base_price', '<=', (string) $f['max_price']);
                    }
                });
            }
            if (! empty($f['q'])) {
                $q->where('tenants.name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']).'%');
            }

            match ($f['sort'] ?? 'rating') {
                'price_asc' => $q->orderByRaw('tenants.min_service_price IS NULL, tenants.min_service_price ASC'),
                'price_desc' => $q->orderByDesc('tenants.min_service_price'),
                'name' => $q->orderBy('tenants.name'),
                'newest' => $q->orderByDesc('tenants.created_at'),
                default => $q->orderByRaw('tenants.rating_avg IS NULL, tenants.rating_avg DESC')->orderByDesc('tenants.rating_count'),
            };
            $q->orderBy('tenants.id');

            $q->with([
                'branches' => fn ($b) => $b->where('status', RecordStatus::Active->value)->with(['city', 'district']),
            ]);

            if (! empty($f['date'])) {
                // Availability is computed per tenant; restrict to tenants with at least one free slot.
                $ids = (clone $q)->limit(200)->pluck('tenants.id');
                $available = $ids->filter(fn ($id) => $this->hasAvailability($id, $f));
                $q->whereIn('tenants.id', $available->all() ?: [0]);
            }

            return $q->paginate($perPage)->withQueryString();
        });
    }

    private function hasAvailability(int $tenantId, array $f): bool
    {
        $branches = Branch::withoutGlobalScopes()->with('tenant')->where('tenant_id', $tenantId)->where('status', RecordStatus::Active->value)->get();
        foreach ($branches as $branch) {
            $serviceIds = DB::table('branch_services')->join('services', 'services.id', '=', 'branch_services.service_id')
                ->where('branch_services.branch_id', $branch->id)->where('branch_services.active', true)
                ->where('services.active', true)->whereNull('services.deleted_at')
                ->when(! empty($f['category']), fn ($q) => $q->whereIn('services.category_id', DB::table('service_categories')->where('slug', $f['category'])->select('id')))
                ->orderBy('services.duration_minutes')->limit(1)->pluck('services.id')->all();
            if ($serviceIds === []) {
                continue;
            }
            try {
                $slots = $this->availability->slotsForDay($branch, $serviceIds, null, (string) $f['date']);
            } catch (\Throwable) {
                continue;
            }
            foreach ($slots as $slot) {
                if (empty($f['time']) || $slot['start']->setTimezone($branch->timezone)->format('H:i') >= $f['time']) {
                    return true;
                }
            }
        }

        return false;
    }
}
