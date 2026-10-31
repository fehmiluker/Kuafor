<?php

namespace App\Services\Booking;

use App\Enums\RecordStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Employee;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which services a branch offers, which employees can perform them,
 * and the effective duration/price (employee override > branch override > base).
 * Every lookup is constrained to the branch's tenant — ids from another tenant
 * simply do not resolve.
 */
final class ServiceResolver
{
    /**
     * @param  list<int>  $serviceIds
     * @return Collection<int, Service> keyed by id, in requested order
     */
    public function servicesForBranch(Branch $branch, array $serviceIds): Collection
    {
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        if ($serviceIds === [] || count($serviceIds) > (int) config('booking.max_services_per_appointment')) {
            throw new DomainRuleException('Lütfen en az bir, en fazla '.config('booking.max_services_per_appointment').' hizmet seçin.', 'invalid_services');
        }
        $services = Service::withoutGlobalScopes()
            ->where('services.tenant_id', $branch->tenant_id)
            ->whereIn('services.id', $serviceIds)
            ->where('services.active', true)
            ->whereNull('services.deleted_at')
            ->whereExists(fn ($q) => $q->from('branch_services')
                ->whereColumn('branch_services.service_id', 'services.id')
                ->where('branch_services.branch_id', $branch->id)
                ->where('branch_services.active', true))
            ->get()->keyBy('id');

        if ($services->count() !== count($serviceIds)) {
            throw new DomainRuleException('Seçilen hizmetlerden biri bu şubede sunulmuyor.', 'invalid_services');
        }

        return collect($serviceIds)->mapWithKeys(fn ($id) => [$id => $services[$id]]);
    }

    /**
     * Active employees of the branch qualified for ALL given services.
     *
     * @param  list<int>  $serviceIds
     * @return Collection<int, Employee>
     */
    public function qualifiedEmployees(Branch $branch, array $serviceIds, ?int $onlyEmployeeId = null): Collection
    {
        $q = Employee::withoutGlobalScopes()
            ->where('employees.tenant_id', $branch->tenant_id)
            ->where('employees.status', RecordStatus::Active->value)
            ->whereNull('employees.deleted_at')
            ->whereExists(fn ($s) => $s->from('employee_branches')
                ->whereColumn('employee_branches.employee_id', 'employees.id')
                ->where('employee_branches.branch_id', $branch->id)
                ->where('employee_branches.active', true));
        if ($onlyEmployeeId !== null) {
            $q->where('employees.id', $onlyEmployeeId);
        }
        foreach (array_unique($serviceIds) as $sid) {
            $q->whereExists(fn ($s) => $s->from('employee_services')
                ->whereColumn('employee_services.employee_id', 'employees.id')
                ->where('employee_services.service_id', $sid)
                ->where('employee_services.active', true)
                ->where(fn ($w) => $w->whereNull('employee_services.branch_id')->orWhere('employee_services.branch_id', $branch->id)));
        }

        return $q->orderBy('employees.id')->get();
    }

    /** @param Collection<int, Service> $services */
    public function quote(Branch $branch, Employee $employee, Collection $services): Quote
    {
        $branchOverrides = BranchService::withoutGlobalScopes()->where('branch_id', $branch->id)
            ->whereIn('service_id', $services->keys())->get()->keyBy('service_id');
        $employeeOverrides = DB::table('employee_services')->where('employee_id', $employee->id)
            ->whereIn('service_id', $services->keys())->where('active', true)
            ->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderByRaw('branch_id IS NULL') // branch-specific row wins
            ->get()->unique('service_id')->keyBy('service_id');

        $lines = [];
        foreach ($services as $service) {
            $bo = $branchOverrides[$service->id] ?? null;
            $eo = $employeeOverrides[$service->id] ?? null;
            $duration = (int) ($eo?->duration_override ?? $bo?->duration_override ?? $service->duration_minutes);
            $price = $eo?->price_override !== null && $eo !== null
                ? Money::parse((string) $eo->price_override)
                : ($bo?->price_override ?? $service->base_price);
            $lines[] = new ServiceLine($service->id, $service->name, $duration, (int) $service->buffer_minutes, $price);
        }

        return new Quote($employee->id, $lines, (int) $employee->buffer_minutes);
    }

    /** Lowest price of a service at a branch, for listings. */
    public function displayPrice(Branch $branch, Service $service): Money
    {
        $bo = BranchService::withoutGlobalScopes()->where('branch_id', $branch->id)->where('service_id', $service->id)->first();

        return $bo?->price_override ?? $service->base_price;
    }
}
