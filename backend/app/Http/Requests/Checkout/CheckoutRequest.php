<?php

namespace App\Http\Requests\Checkout;

use App\Enums\FulfilmentMethod;
use App\Models\UserAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body: {"address_id": "...", "fulfilment": {"<farm id>": "pickup" | "delivery"}}.
 * Farms left out of "fulfilment" use pickup when they offer it, otherwise delivery.
 */
class CheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_id' => ['bail', 'nullable', 'uuid'],
            'fulfilment' => ['sometimes', 'array'],
            'fulfilment.*' => ['required', Rule::enum(FulfilmentMethod::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('address_id') && $validator->errors()->isEmpty() && $this->address() === null) {
                    $validator->errors()->add('address_id', __('Choose one of your saved addresses.'));
                }
            },
        ];
    }

    public function address(): ?UserAddress
    {
        $id = $this->input('address_id');

        return is_string($id) && Str::isUuid($id) ? $this->user()->addresses()->whereKey($id)->first() : null;
    }

    /**
     * @return array<string, string>
     */
    public function methods(): array
    {
        return (array) $this->validated('fulfilment', []);
    }
}
