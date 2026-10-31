<?php

namespace App\Models;

use App\Enums\PlatformRole;
use App\Enums\RecordStatus;
use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $guarded = ['id', 'two_factor_secret', 'two_factor_confirmed_at', 'status'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    /** Mirror DB defaults so freshly created instances behave like loaded ones. */
    protected $attributes = ['status' => 'active', 'locale' => 'tr'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'status' => RecordStatus::class,
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('status')->withTimestamps();
    }

    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('status', RecordStatus::Active->value)
            ->where('tenants.status', '!=', 'closed');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CustomerFavorite::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?? RecordStatus::Active) === RecordStatus::Active;
    }

    public function isMemberOf(Tenant|int $tenant): bool
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->activeTenants()->where('tenants.id', $id)->exists();
    }

    /** Run a role/permission check inside a specific Spatie team, restoring the previous team. */
    public function inTeam(int $teamId, \Closure $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($teamId);
        $this->unsetRelation('roles')->unsetRelation('permissions');
        try {
            return $callback($this);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function hasPlatformRole(PlatformRole ...$roles): bool
    {
        return $this->inTeam((int) config('tenancy.platform_team_id'),
            fn (User $u) => $u->hasAnyRole(array_map(fn ($r) => $r->value, $roles)));
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasPlatformRole(PlatformRole::SuperAdmin);
    }

    public function isPlatformStaff(): bool
    {
        return $this->hasPlatformRole(PlatformRole::SuperAdmin, PlatformRole::Support);
    }

    public function hasPlatformPermission(string $permission): bool
    {
        return $this->inTeam((int) config('tenancy.platform_team_id'), fn (User $u) => $u->hasPermissionTo($permission));
    }

    /** Tenant role keys of this user in the given tenant. */
    public function tenantRoles(Tenant|int $tenant): array
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.tenant_id', $id)
            ->pluck('roles.name')->all();
    }

    public function hasTenantRole(Tenant|int $tenant, TenantRole ...$roles): bool
    {
        return array_intersect($this->tenantRoles($tenant), array_map(fn ($r) => $r->value, $roles)) !== [];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
