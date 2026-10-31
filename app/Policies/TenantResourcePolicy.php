<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\AccessScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared checks for tenant-owned records: the record must belong to the
 * CURRENT tenant context, the user must be an active member holding the
 * permission inside that tenant, and branch-scoped roles must be assigned to
 * the record's branch. Hiding a button is never the only protection.
 */
abstract class TenantResourcePolicy
{
    protected function tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }

    protected function can(User $user, string $permission): bool
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $user->isActive() || ! $user->isMemberOf($tenant)) {
            return false;
        }

        return $user->inTeam($tenant->id, fn (User $u) => $u->hasPermissionTo($permission));
    }

    protected function owns(Model $record): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && (int) $record->getAttribute('tenant_id') === $tenant->id;
    }

    protected function inBranch(User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }

        return app(AccessScope::class)->canAccessBranch($user, $this->tenant(), $branchId);
    }

    protected function check(User $user, Model $record, string $permission, ?int $branchId = null): bool
    {
        return $this->owns($record) && $this->can($user, $permission) && $this->inBranch($user, $branchId);
    }
}
