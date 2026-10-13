<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreVariantRequest;
use App\Http\Requests\Catalog\UpdateVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\VariantService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProductVariantController extends Controller
{
    public function store(StoreVariantRequest $request, Product $product, VariantService $variants): ProductVariantResource
    {
        $variant = $variants->create($product, $request->spec());

        return new ProductVariantResource($variant->setRelation('product', $product)->load(['unit', 'priceTiers']));
    }

    public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant, VariantService $variants): ProductVariantResource
    {
        $variant = $variants->update($variant, $request->spec());

        return new ProductVariantResource($variant->setRelation('product', $product));
    }

    public function destroy(Product $product, ProductVariant $variant, VariantService $variants): Response
    {
        Gate::authorize('manage', $product);

        $variants->delete($variant);

        return response()->noContent();
    }
}
