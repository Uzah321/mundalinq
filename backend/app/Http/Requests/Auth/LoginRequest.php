<?php

namespace App\Http\Requests\Auth;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $login = $this->input('login');

        if (is_string($login)) {
            $this->merge([
                'login' => $this->isEmail($login) ? Str::lower(trim($login)) : Phone::normalize($login),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'], // phone number or email address
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function loginColumn(): string
    {
        return $this->isEmail($this->validated('login')) ? 'email' : 'phone';
    }

    private function isEmail(string $login): bool
    {
        return str_contains($login, '@');
    }
}
