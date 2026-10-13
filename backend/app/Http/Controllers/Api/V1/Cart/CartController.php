<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Services\Cart\CartService;
use App\Services\Checkout\QuoteBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartController extends Controller
{
    /**
     * The cart grouped by farm with current prices; items that need attention carry a "problem".
     */
    public function show(Request $request, CartService $carts, QuoteBuilder $quotes): QuoteResource
    {
        return new QuoteResource($quotes->build($carts->for($request->user()), $request->user()));
    }

    public function clear(Request $request, CartService $carts): Response
    {
        $carts->clear($request->user());

        return response()->noContent();
    }
}
