<?php

namespace App\Rules;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Rejects ids that do not exist IN THE CURRENT TENANT (forged branch_id/employee_id/service_id...). */
class BelongsToCurrentTenant implements ValidationRule
{
    public function __construct(private readonly string $table, private readonly bool $withTrashed = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tenantId = app(TenantContext::class)->id();
        $ids = is_array($value) ? $value : [$value];
        $q = DB::table($this->table)->where('tenant_id', $tenantId)->whereIn('id', array_map('intval', $ids));
        if (! $this->withTrashed && Schema::hasColumn($this->table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if (! $tenantId || $q->count() !== count(array_unique(array_map('intval', $ids)))) {
            $fail('Seçilen kayıt bu işletmeye ait değil.');
        }
    }
}
