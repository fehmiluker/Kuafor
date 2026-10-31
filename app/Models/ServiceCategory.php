<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** tenant_id NULL = platform-wide default category (used by search). */
class ServiceCategory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => RecordStatus::class];
    }

    /** Platform categories + the current tenant's own categories. */
    public function scopeVisibleToTenant(Builder $q): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        return $q->where(fn ($w) => $w->whereNull('tenant_id')->when($tenantId, fn ($w2) => $w2->orWhere('tenant_id', $tenantId)));
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
