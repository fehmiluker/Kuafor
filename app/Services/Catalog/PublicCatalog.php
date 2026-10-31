<?php

namespace App\Services\Catalog;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Booking\ServiceResolver;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-model for public pages/API. Every query is pinned to one tenant explicitly. */
final class PublicCatalog
{
    public function __construct(private readonly ServiceResolver $resolver) {}

    /** Tenant reachable by direct link (active + usable subscription). */
    public function bookableTenant(string $slug): ?Tenant
    {
        return Tenant::query()->bookable()->where('slug', $slug)->first();
    }

    /** Public branch lookup: active branch of a bookable tenant. */
    public function bookableBranch(int $branchId): ?Branch
    {
        $branch = Branch::withoutGlobalScopes()->whereKey($branchId)->where('status', RecordStatus::Active->value)->first();
        if (! $branch) {
            return null;
        }
        $tenant = Tenant::query()->bookable()->whereKey($branch->tenant_id)->first();
        if (! $tenant) {
            return null;
        }
        $branch->setRelation('tenant', $tenant);

        return $branch;
    }

    /** @return Collection<int, Branch> */
    public function branches(Tenant $tenant): Collection
    {
        return Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', RecordStatus::Active->value)
            ->with(['city', 'district', 'workingHours' => fn ($q) => $q->withoutGlobalScopes()->where('active', true)])
            ->orderBy('id')->get();
    }

    /** Services offered at the branch with effective (branch) price/duration. */
    public function services(Branch $branch): Collection
    {
        $rows = DB::table('branch_services')->where('branch_id', $branch->id)->where('active', true)->get()->keyBy('service_id');

        return Service::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->where('active', true)
            ->whereIn('id', $rows->keys())->with('category')->orderBy('category_id')->orderBy('name')->get()
            ->each(function (Service $s) use ($rows) {
                $row = $rows[$s->id];
                $s->effective_price = $row->price_override !== null ? Money::parse((string) $row->price_override) : $s->base_price;
                $s->effective_duration = $row->duration_override ?? $s->duration_minutes;
            });
    }

    /** @param list<int> $serviceIds */
    public function employees(Branch $branch, array $serviceIds = []): Collection
    {
        if ($serviceIds) {
            return $this->resolver->qualifiedEmployees($branch, $serviceIds);
        }

        return Employee::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->where('status', RecordStatus::Active->value)
            ->whereExists(fn ($q) => $q->from('employee_branches')->whereColumn('employee_branches.employee_id', 'employees.id')
                ->where('employee_branches.branch_id', $branch->id)->where('employee_branches.active', true))
            ->orderBy('display_name')->get();
    }

    /** Local date + time in the branch timezone -> UTC instant. */
    public function toUtc(Branch $branch, string $date, string $time): CarbonImmutable
    {
        return LocalTime::toUtc($date, $time, $branch->timezone);
    }
}
