<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization happens in policies/controllers
    }

    /** Normalise phone inputs to E.164 before validation. */
    protected function normalizePhones(string ...$keys): void
    {
        foreach ($keys as $key) {
            if ($this->filled($key)) {
                $this->merge([$key => PhoneNumber::normalize((string) $this->input($key)) ?? $this->input($key)]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute alanı zorunludur.',
            'accepted' => ':attribute onaylanmalıdır.',
            'max' => ':attribute çok uzun.',
            'min' => ':attribute çok kısa.',
            'email' => 'Geçerli bir e-posta adresi girin.',
            'date_format' => ':attribute biçimi geçersiz.',
            'integer' => ':attribute sayı olmalıdır.',
            'prohibited' => 'Geçersiz istek.',
        ];
    }
}
