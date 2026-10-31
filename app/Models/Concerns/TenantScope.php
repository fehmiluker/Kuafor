<?php

namespace App\Models\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        if ($context->isBypassed()) {
            return;
        }
        if ($context->has()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());

            return;
        }
        // Fail closed.
        $builder->whereRaw('1 = 0');
    }
}
