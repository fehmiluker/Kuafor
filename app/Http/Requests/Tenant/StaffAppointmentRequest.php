<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\ApiFormRequest;
use App\Models\Appointment;
use App\Rules\BelongsToCurrentTenant;
use App\Rules\Phone;

class StaffAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhones('phone');
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', new BelongsToCurrentTenant('branches')],
            'service_ids' => ['required', 'array', 'min:1', 'max:'.config('booking.max_services_per_appointment'), new BelongsToCurrentTenant('services')],
            'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'customer_id' => ['nullable', 'integer', new BelongsToCurrentTenant('tenant_customers')],
            'full_name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'phone' => ['required_without:customer_id', 'nullable', new Phone],
            'email' => ['nullable', 'email:rfc'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
