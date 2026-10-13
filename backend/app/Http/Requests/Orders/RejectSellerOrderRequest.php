<?php

namespace App\Http\Requests\Orders;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RejectSellerOrderRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('fulfil', $this->route('sellerOrder'));
    }

    public function rules(): array
    {
        return [
            // Shown to the buyer.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }
}
