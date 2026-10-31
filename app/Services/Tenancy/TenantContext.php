<?php

namespace App\Services\Tenancy;

use App\Exceptions\TenantAccessDeniedException;
use App\Models\Tenant;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the tenant the current request/job operates on.
 *
 * Tenant-owned models apply a global scope from this context. The scope FAILS
 * CLOSED: with no tenant set and no explicit platform bypass, queries return no
 * rows. Code that legitimately spans tenants (super admin, public search,
 * scheduler) must opt in via {@see self::asPlatform()}.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private int $bypassDepth = 0;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->id ?? config('tenancy.platform_team_id'));
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function require(): Tenant
    {
        if (! $this->tenant) {
            throw new TenantAccessDeniedException('İşletme bağlamı bulunamadı.');
        }

        return $this->tenant;
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Run $callback inside $tenant, restoring the previous context afterwards
     * (safe for nested calls and long-lived queue workers).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $previousBypass = $this->bypassDepth;
        $this->bypassDepth = 0;
        $this->set($tenant);
        try {
            return $callback();
        } finally {
            $this->bypassDepth = $previousBypass;
            $this->set($previous);
        }
    }

    /**
     * Explicit cross-tenant access. Use only in platform code paths.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asPlatform(Closure $callback): mixed
    {
        $this->bypassDepth++;
        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function forget(): void
    {
        $this->bypassDepth = 0;
        $this->set(null);
    }
}
