<?php

namespace App\Http\Requests\Checkout;

use App\Rules\DecimalString;

/**
 * The checkout body plus the total the buyer saw and agreed to.
 */
class PlaceOrderRequest extends CheckoutRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_total' => ['required', DecimalString::money()],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
