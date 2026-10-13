<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutRequest;
use App\Http\Resources\QuoteResource;
use App\Services\Cart\CartService;
use App\Services\Checkout\QuoteBuilder;

class CheckoutController extends Controller
{
    /**
     * Price the cart for checkout: fulfilment per farm, delivery fees and the total to confirm.
     * Nothing is reserved until the order is placed.
     */
    public function quote(CheckoutRequest $request, CartService $carts, QuoteBuilder $quotes): QuoteResource
    {
        return new QuoteResource($quotes->build(
            $carts->for($request->user()),
            $request->user(),
            true,
            $request->address(),
            $request->methods(),
        ));
    }
}
