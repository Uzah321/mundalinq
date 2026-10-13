<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ListingPromotions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff featuring a listing for free, or ending its featuring early.
 */
class ProductPromotionController extends Controller
{
    public function __construct(private ListingPromotions $promotions) {}

    public function store(Request $request, Product $product): ManagedProductResource
    {
        $validated = $request->validate(['weeks' => ['required', 'integer', Rule::in($this->promotions->weekOptions())]]);

        $this->promotions->grant($product, $request->user(), (int) $validated['weeks']);

        return new ManagedProductResource($product->fresh()->load(ProductResource::RELATIONS));
    }

    public function destroy(Request $request, Product $product): ManagedProductResource
    {
        $this->promotions->stop($product, $request->user());

        return new ManagedProductResource($product->fresh()->load(ProductResource::RELATIONS));
    }
}
