<?php

namespace App\Services\Billing;

use App\Enums\RecordStatus;
use App\Exceptions\PlanLimitException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Reads entitlements from the subscription SNAPSHOT (frozen at period start),
 * so editing a plan never silently changes what existing subscribers bought.
 */
final class EntitlementService
{
    public function subscription(Tenant $tenant): ?Subscription
    {
        return Subscription::where('tenant_id', $tenant->id)->latest('id')->first();
    }

    public function entitlements(Tenant $tenant): array
    {
        $sub = $this->subscription($tenant);
        if (! $sub || ! $sub->status->isUsable()) {
            return ['max_branches' => 0, 'max_employees' => 0, 'features' => []];
        }

        return $sub->entitlements_snapshot + ['features' => []];
    }

    public function hasFeature(Tenant $tenant, string $feature): bool
    {
        return (bool) ($this->entitlements($tenant)['features'][$feature] ?? false);
    }

    public function isUsable(Tenant $tenant): bool
    {
        return (bool) $this->subscription($tenant)?->status->isUsable();
    }

    public function activeBranchCount(Tenant $tenant): int
    {
        return Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->where('status', RecordStatus::Active->value)->count();
    }

    public function activeEmployeeCount(Tenant $tenant): int
    {
        return Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->where('status', RecordStatus::Active->value)->count();
    }

    public function canAddBranch(Tenant $tenant): bool
    {
        return $this->activeBranchCount($tenant) < (int) ($this->entitlements($tenant)['max_branches'] ?? 0);
    }

    public function canAddEmployee(Tenant $tenant): bool
    {
        return $this->activeEmployeeCount($tenant) < (int) ($this->entitlements($tenant)['max_employees'] ?? 0);
    }

    public function assertCanAddBranch(Tenant $tenant): void
    {
        if (! $this->canAddBranch($tenant)) {
            throw new PlanLimitException('Paketinizin şube limitine ulaştınız. Daha fazla şube için paketinizi yükseltin.', 'max_branches');
        }
    }

    public function assertCanAddEmployee(Tenant $tenant): void
    {
        if (! $this->canAddEmployee($tenant)) {
            throw new PlanLimitException('Paketinizin personel limitine ulaştınız. Daha fazla personel için paketinizi yükseltin.', 'max_employees');
        }
    }

    /** After a downgrade: how many active branches/employees must be deactivated. */
    public function overLimit(Tenant $tenant): array
    {
        $e = $this->entitlements($tenant);

        return [
            'branches' => max(0, $this->activeBranchCount($tenant) - (int) ($e['max_branches'] ?? 0)),
            'employees' => max(0, $this->activeEmployeeCount($tenant) - (int) ($e['max_employees'] ?? 0)),
        ];
    }

    public function usage(Tenant $tenant): array
    {
        $e = $this->entitlements($tenant);

        return [
            'branches' => ['used' => $this->activeBranchCount($tenant), 'limit' => (int) ($e['max_branches'] ?? 0)],
            'employees' => ['used' => $this->activeEmployeeCount($tenant), 'limit' => (int) ($e['max_employees'] ?? 0)],
            'features' => $e['features'] ?? [],
        ];
    }
}
