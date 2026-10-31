<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\ApiFormRequest;
use App\Models\Service;
use App\Rules\BelongsToCurrentTenant;

class ServiceRequest extends ApiFormRequest
{
    /** Authorize before validating so unauthorized callers get 403, not validation details. */
    public function authorize(): bool
    {
        $record = $this->route('service');

        return $record instanceof Service ? $this->user()->can('update', $record) : $this->user()->can('create', Service::class);
    }

    public function rules(): array
    {
        $req = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'duration_minutes' => [$req, 'integer', 'between:5,720'],
            'buffer_minutes' => ['nullable', 'integer', 'between:0,240'],
            'base_price' => [$req, 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'active' => ['sometimes', 'boolean'],
            'branch_ids' => ['sometimes', 'array', new BelongsToCurrentTenant('branches')],
            'branch_ids.*' => ['integer'],
            'branch_overrides' => ['sometimes', 'array'],
            'branch_overrides.*.branch_id' => ['required', 'integer'],
            'branch_overrides.*.price' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'branch_overrides.*.duration' => ['nullable', 'integer', 'between:5,720'],
        ];
    }
}
