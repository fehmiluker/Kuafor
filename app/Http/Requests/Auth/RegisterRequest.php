<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Rules\Phone;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizePhones('phone');
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', new Phone, 'unique:users,phone_e164'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'kvkk_acknowledged' => ['accepted'],
            'business_name' => ['nullable', 'string', 'min:2', 'max:150'],
            'plan' => ['nullable', 'string', 'exists:plans,slug'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'Ad soyad', 'email' => 'E-posta', 'phone' => 'Telefon', 'password' => 'Şifre', 'kvkk_acknowledged' => 'Aydınlatma metni', 'business_name' => 'İşletme adı'];
    }
}
