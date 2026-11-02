<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\ApiFormRequest;
use App\Models\Branch;
use App\Rules\Phone;
use Illuminate\Validation\Rule;

class BranchRequest extends ApiFormRequest
{
    /** Authorize before validating so unauthorized callers get 403, not validation details. */
    public function authorize(): bool
    {
        $record = $this->route('branch');

        return $record instanceof Branch ? $this->user()->can('update', $record) : $this->user()->can('create', Branch::class);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhones('phone');
    }

    public function rules(): array
    {
        $partial = $this->isMethod('PATCH');
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:120'],
            'phone' => ['nullable', new Phone],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('city_id', $this->input('city_id'))],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'timezone' => ['nullable', 'timezone:all'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'working_hours' => ['sometimes', 'array'],
            'working_hours.*.day_of_week' => ['required_with:working_hours', 'integer', 'between:0,6'],
            'working_hours.*.opens' => ['required_with:working_hours', 'date_format:H:i'],
            'working_hours.*.closes' => ['required_with:working_hours', 'date_format:H:i', 'after:working_hours.*.opens'],
            'whatsapp_manager_numbers' => ['sometimes', 'array', 'max:5'],
            'whatsapp_manager_numbers.*' => [new Phone],
            'home_service_enabled' => ['sometimes', 'boolean'],
            'home_requires_approval' => ['sometimes', 'boolean'],
            'home_travel_minutes' => ['nullable', 'integer', 'between:0,240'],
            'home_min_total' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'home_areas' => ['sometimes', 'array', 'max:100'],
            'home_areas.*.district_id' => ['required', 'integer', 'exists:districts,id'],
            'home_areas.*.fee' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
        ];
    }
}
