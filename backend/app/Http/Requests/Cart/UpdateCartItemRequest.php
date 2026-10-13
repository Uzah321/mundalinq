<?php

namespace App\Http\Requests\Cart;

use App\Models\CartItem;
use App\Rules\DecimalString;
use App\Support\Quantity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->item()->cart->user_id === $this->user()->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', DecimalString::quantity()],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                CartRules::check(
                    $validator->errors(),
                    CartRules::variant($this->item()->product_variant_id),
                    Quantity::of($this->input('quantity')),
                    $this->user(),
                    'quantity',
                );
            },
        ];
    }

    private function item(): CartItem
    {
        return $this->route('item');
    }
}
