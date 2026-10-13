<?php

namespace App\Http\Requests\Cart;

use App\Models\ProductVariant;
use App\Rules\DecimalString;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body: {"variant_id": "...", "quantity": "5"}. Sets the quantity if the item is already in the cart.
 */
class AddCartItemRequest extends FormRequest
{
    private ?ProductVariant $variant = null;

    public function rules(): array
    {
        return [
            'variant_id' => ['bail', 'required', 'uuid', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
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

                $this->variant = CartRules::variant($this->input('variant_id'));
                CartRules::check($validator->errors(), $this->variant, Quantity::of($this->input('quantity')), $this->user(), 'variant_id');

                $cart = $this->user()->cart;
                $max = config('mundalinq.orders.max_cart_items');

                if ($cart !== null
                    && ! $cart->items()->where('product_variant_id', $this->variant->id)->exists()
                    && $cart->items()->count() >= $max) {
                    $validator->errors()->add('variant_id', __('Your cart is full (:max items). Place an order or remove something first.', ['max' => $max]));
                }
            },
        ];
    }

    public function variant(): ProductVariant
    {
        return $this->variant;
    }
}
