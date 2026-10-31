<?php

namespace Tests\Support;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Totp;

trait ActsInTenant
{
    protected function api(User $user, ?Tenant $tenant = null): static
    {
        $this->actingAs($user, 'sanctum');
        if ($tenant) {
            $this->withHeader('X-Tenant', $tenant->slug);
        }

        return $this->withHeader('Accept', 'application/json');
    }

    protected function member(Tenant $tenant, TenantRole $role, array $branchIds = []): User
    {
        $user = User::factory()->create();
        app(TenantProvisioner::class)->addMember($tenant, $user, $role, $branchIds);

        return $user;
    }

    protected function owner(Tenant $tenant): User
    {
        return User::findOrFail($tenant->owner_user_id);
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $u->inTeam(0, fn ($x) => $x->assignRole(PlatformRole::SuperAdmin->value));

        return $u;
    }

    /** Web session for a super admin who has passed MFA. */
    protected function asAdminWeb(): static
    {
        return $this->actingAs($this->superAdmin())->withSession(['two_factor_passed' => true]);
    }
}
