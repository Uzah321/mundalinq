<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ListingPromotions;
use App\Support\Market;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Farms featuring their own listings, paid from their earnings.
 */
class ProductPromotionController extends Controller
{
    public function __construct(private ListingPromotions $promotions) {}

    /**
     * What featuring costs, for the app to show before the farm decides.
     */
    public function pricing(): JsonResponse
    {
        return response()->json(['data' => [
            'price_per_week' => Money::format($this->promotions->priceMinor(1)),
            'currency' => Market::currency(),
            'weeks' => $this->promotions->weekOptions(),
        ]]);
    }

    /**
     * Body: {"weeks": 1}. The price is taken from the farm's available earnings, so only its owner can.
     */
    public function store(Request $request, Product $product): ManagedProductResource
    {
        Gate::authorize('manage', $product);
        Gate::authorize('spendEarnings', $product->farm);
        $validated = $request->validate(['weeks' => ['required', 'integer', Rule::in($this->promotions->weekOptions())]]);

        $this->promotions->buy($product, $request->user(), (int) $validated['weeks']);

        return new ManagedProductResource($product->fresh()->load(ProductResource::RELATIONS));
    }
}
