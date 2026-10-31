<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantAccessDeniedException;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned model: queries are scoped to the current tenant, tenant_id is
 * filled from the context on create and can never be changed afterwards or be
 * set to a different tenant than the active one.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                if ($model->tenant_id && (int) $model->tenant_id !== $context->id()) {
                    throw new TenantAccessDeniedException('Başka bir işletmeye kayıt oluşturulamaz.');
                }
                $model->tenant_id = $context->id();
            } elseif (! $model->tenant_id && ! (method_exists($model, 'allowsNullTenant') && $model->allowsNullTenant())) {
                throw new TenantAccessDeniedException('İşletme bağlamı olmadan kayıt oluşturulamaz.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new TenantAccessDeniedException('Kaydın işletmesi değiştirilemez.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
