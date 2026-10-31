<?php

namespace App\Http\Requests\Public;

use App\Http\Requests\ApiFormRequest;
use App\Rules\Phone;

class StoreAppointmentRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizePhones('phone');
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'service_ids' => ['required', 'array', 'min:1', 'max:'.config('booking.max_services_per_appointment')],
            'service_ids.*' => ['integer', 'distinct'],
            'employee_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', new Phone],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'kvkk_acknowledged' => ['accepted'],
            'whatsapp_opt_in' => ['boolean'],
            'marketing_opt_in' => ['boolean'],
            'verification_code' => ['nullable', 'digits:6'],
            // Honeypot: bots fill every field.
            'website' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['full_name' => 'Ad soyad', 'phone' => 'Telefon', 'kvkk_acknowledged' => 'Aydınlatma metni', 'date' => 'Tarih', 'time' => 'Saat', 'service_ids' => 'Hizmet'];
    }
}
