<?php

namespace App\Http\Requests\Orders;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class HandoverRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('fulfil', $this->route('sellerOrder'));
    }

    public function rules(): array
    {
        return [
            // The code the buyer's app shows for this order.
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
