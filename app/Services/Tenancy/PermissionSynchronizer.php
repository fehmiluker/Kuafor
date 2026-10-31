<?php

namespace App\Services\Tenancy;

use App\Support\Permissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates/updates roles and permissions from App\Support\Permissions. Roles are
 * global definitions (tenant_id NULL); assignments are per tenant (Spatie team).
 */
final class PermissionSynchronizer
{
    public function sync(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (array_merge(Permissions::TENANT, Permissions::PLATFORM) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (Permissions::tenantMatrix() + Permissions::platformMatrix() as $roleName => $perms) {
            $role = Role::query()->whereNull('tenant_id')->where('name', $roleName)->where('guard_name', 'web')->first()
                ?? Role::query()->forceCreate(['name' => $roleName, 'guard_name' => 'web', 'tenant_id' => null]);
            $role->syncPermissions($perms);
        }
        $registrar->forgetCachedPermissions();
    }
}
