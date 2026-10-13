<?php

namespace App\Http\Requests\Quotes;

use App\Enums\FulfilmentMethod;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body: {"farm_id", "product_variant_id", "unit_price": "1.10", "quantity": "200", "fulfilment": "pickup|delivery",
 * "delivery_fee"?, "message"?, "valid_until"?}. The price and quantity are in the selling option's unit.
 */
class MakeQuoteOfferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'farm_id' => ['required', 'uuid'],
            'product_variant_id' => ['required', 'uuid'],
            'unit_price' => ['required', DecimalString::money()],
            'quantity' => ['required', DecimalString::quantity()],
            'fulfilment' => ['required', Rule::enum(FulfilmentMethod::class)],
            'delivery_fee' => ['nullable', DecimalString::money()->allowingZero()],
            'message' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
