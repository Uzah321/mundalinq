<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;

class CartService
{
    public function for(User $user): Cart
    {
        // createOrFirst survives two first requests racing to create the same cart.
        return Cart::createOrFirst(['user_id' => $user->id]);
    }

    /**
     * Set how much of a selling option is in the cart (adding it if needed).
     */
    public function put(User $user, ProductVariant $variant, string $quantity): CartItem
    {
        return $this->for($user)->items()->updateOrCreate(
            ['product_variant_id' => $variant->id],
            ['quantity' => $quantity],
        );
    }

    public function clear(User $user): void
    {
        $user->cart?->items()->delete();
    }
}
