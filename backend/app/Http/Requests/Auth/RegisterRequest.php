<?php

namespace App\Http\Requests\Auth;

use App\Enums\RoleSlug;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => Phone::normalize($this->input('phone')),
            'email' => is_string($this->input('email')) && trim($this->input('email')) !== ''
                ? Str::lower(trim($this->input('email')))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN, Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::in(array_map(fn (RoleSlug $role) => $role->value, RoleSlug::selfRegistrable()))],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a valid phone number, e.g. 0771234567 or +263771234567.'),
        ];
    }
}
