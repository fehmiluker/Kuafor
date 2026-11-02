<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantRole;
use App\Http\Requests\ApiFormRequest;
use App\Models\Employee;
use App\Rules\BelongsToCurrentTenant;
use Illuminate\Validation\Rule;

class EmployeeRequest extends ApiFormRequest
{
    /** Authorize before validating so unauthorized callers get 403, not validation details. */
    public function authorize(): bool
    {
        $record = $this->route('employee');

        return $record instanceof Employee ? $this->user()->can('update', $record) : $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        $req = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'display_name' => [$req, 'string', 'min:2', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'buffer_minutes' => ['nullable', 'integer', 'between:0,120'],
            'home_service' => ['sometimes', 'boolean'],
            'branch_ids' => [$req, 'array', 'min:1', new BelongsToCurrentTenant('branches')],
            'service_ids' => ['sometimes', 'array', new BelongsToCurrentTenant('services')],
            'user_email' => ['nullable', 'email:rfc'],
            'role' => ['nullable', Rule::in(array_diff(TenantRole::values(), [TenantRole::Owner->value]))],
            'shifts' => ['sometimes', 'array'],
            'shifts.*.branch_id' => ['required', 'integer'],
            'shifts.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'shifts.*.opens' => ['required', 'date_format:H:i'],
            'shifts.*.closes' => ['required', 'date_format:H:i'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }
}
