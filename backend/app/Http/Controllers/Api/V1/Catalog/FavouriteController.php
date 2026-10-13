<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Services\Catalog\ProductSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Listings a buyer saved. Paused or expired listings stay saved but are left out until they are live again.
 */
class FavouriteController extends Controller
{
    /**
     * Most recently saved first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->select('products.*')
            ->join('favourites', 'favourites.product_id', '=', 'products.id')
            ->where('favourites.user_id', $request->user()->id)
            ->visibleToBuyers()
            ->with(ProductSearch::cardRelations())
            ->orderByDesc('favourites.created_at')
            ->orderBy('products.id')
            ->paginate(20);

        $products->each(fn (Product $product) => $product->setAttribute('is_favourite', true));

        return ProductCardResource::collection($products);
    }

    /**
     * Idempotent: saving twice keeps one favourite.
     */
    public function store(Request $request, Product $product): Response
    {
        Gate::authorize('view', $product);

        $request->user()->favourites()->firstOrCreate(['product_id' => $product->id]);

        return response()->noContent();
    }

    public function destroy(Request $request, Product $product): Response
    {
        $request->user()->favourites()->where('product_id', $product->id)->delete();

        return response()->noContent();
    }
}
