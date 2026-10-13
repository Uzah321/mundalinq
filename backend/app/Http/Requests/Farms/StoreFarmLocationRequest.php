<?php

namespace App\Http\Requests\Farms;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreFarmLocationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('farm'));
    }

    public function rules(): array
    {
        return [
            ...LocationRules::for(),
            'name' => ['required', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
