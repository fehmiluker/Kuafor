<?php

namespace App\Services\Tenancy;

use App\Enums\TenantRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Row-level scoping inside a tenant:
 *  - owner/admin: every branch
 *  - branch_manager/receptionist: branches assigned in branch_user
 *  - employee: only appointments where they are the assigned employee
 */
final class AccessScope
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /** @return list<int>|null null = all branches of the tenant */
    public function branchIds(User $user, Tenant $tenant): ?array
    {
        return $this->memo["b:{$user->id}:{$tenant->id}"] ??= $this->resolveBranchIds($user, $tenant);
    }

    private function resolveBranchIds(User $user, Tenant $tenant): ?array
    {
        if ($user->hasTenantRole($tenant, TenantRole::Owner, TenantRole::Admin)) {
            return null;
        }
        $fromAssignments = DB::table('branch_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('branch_id');
        $fromEmployee = DB::table('employee_branches')
            ->join('employees', 'employees.id', '=', 'employee_branches.employee_id')
            ->where('employees.tenant_id', $tenant->id)->where('employees.user_id', $user->id)
            ->whereNull('employees.deleted_at')
            ->pluck('employee_branches.branch_id');

        return $fromAssignments->merge($fromEmployee)->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function canAccessBranch(User $user, Tenant $tenant, int $branchId): bool
    {
        $ids = $this->branchIds($user, $tenant);

        return $ids === null || in_array($branchId, $ids, true);
    }

    /** Employee record if the user's ONLY role here is `employee` (restricted to own appointments). */
    public function restrictedEmployeeId(User $user, Tenant $tenant): ?int
    {
        return $this->memo["e:{$user->id}:{$tenant->id}"] ??= (function () use ($user, $tenant) {
            $roles = $user->tenantRoles($tenant);
            if ($roles !== [TenantRole::Employee->value]) {
                return null;
            }

            return (int) (Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('id') ?? 0);
        })();
    }

    public function scopeBranches(Builder $query, User $user, Tenant $tenant, string $column = 'branch_id'): Builder
    {
        $ids = $this->branchIds($user, $tenant);

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $ids ?: [0]);
    }

    public function scopeAppointments(Builder $query, User $user, Tenant $tenant): Builder
    {
        $this->scopeBranches($query, $user, $tenant);
        $employeeId = $this->restrictedEmployeeId($user, $tenant);
        if ($employeeId !== null) {
            $query->where('appointments.employee_id', $employeeId);
        }

        return $query;
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
