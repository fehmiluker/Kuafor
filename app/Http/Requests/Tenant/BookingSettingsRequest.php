<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\ApiFormRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class BookingSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->require();

        return $this->user()->inTeam($tenant->id, fn ($u) => $u->hasPermissionTo('settings.manage'));
    }

    public function rules(): array
    {
        return [
            'approval_mode' => ['sometimes', Rule::in(['manual', 'automatic'])],
            'slot_interval_minutes' => ['sometimes', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'min_notice_minutes' => ['sometimes', 'integer', 'between:0,10080'],
            'max_horizon_days' => ['sometimes', 'integer', 'between:1,365'],
            'cancellation_cutoff_minutes' => ['sometimes', 'integer', 'between:0,10080'],
            'pending_ttl_minutes' => ['sometimes', 'integer', 'between:5,2880'],
            'proposal_ttl_minutes' => ['sometimes', 'integer', 'between:5,2880'],
            'reminder_hours_before' => ['sometimes', 'integer', 'between:1,168'],
            'require_phone_verification' => ['sometimes', 'boolean'],
            'max_pending_per_phone' => ['sometimes', 'integer', 'between:1,20'],
        ];
    }
}
