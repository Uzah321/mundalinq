<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.config('mundalinq.otp.length')],
        ];
    }
}
