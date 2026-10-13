<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Sent to the farmer by SMS, so keep it short and actionable.
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }
}
