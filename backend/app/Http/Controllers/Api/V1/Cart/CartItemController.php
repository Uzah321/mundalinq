<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\QuoteResource;
use App\Models\CartItem;
use App\Services\Cart\CartService;
use App\Services\Checkout\QuoteBuilder;
use Illuminate\Http\Request;

/**
 * Every change returns the updated cart, so the app can re-render from one response.
 */
class CartItemController extends Controller
{
    public function __construct(private CartService $carts, private QuoteBuilder $quotes) {}

    public function store(AddCartItemRequest $request): QuoteResource
    {
        $this->carts->put($request->user(), $request->variant(), (string) $request->validated('quantity'));

        return $this->cart($request);
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): QuoteResource
    {
        $item->update(['quantity' => (string) $request->validated('quantity')]);

        return $this->cart($request);
    }

    public function destroy(Request $request, CartItem $item): QuoteResource
    {
        abort_unless($item->cart->user_id === $request->user()->id, 404);

        $item->delete();

        return $this->cart($request);
    }

    private function cart(Request $request): QuoteResource
    {
        return new QuoteResource($this->quotes->build($this->carts->for($request->user()), $request->user()));
    }
}
