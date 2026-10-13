<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ReorderProductImagesRequest;
use App\Http\Requests\Catalog\UploadProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProductImageController extends Controller
{
    /**
     * Multipart upload of one photo (field "image"). The first photo becomes the cover.
     */
    public function store(UploadProductImageRequest $request, Product $product, ProductImageService $images): ProductImageResource
    {
        return new ProductImageResource($images->add($product, $request->file('image')));
    }

    /**
     * Body: {"image_ids": [...]} listing every photo, cover first.
     */
    public function reorder(ReorderProductImagesRequest $request, Product $product, ProductImageService $images): AnonymousResourceCollection
    {
        $images->reorder($product, $request->validated('image_ids'));

        return ProductImageResource::collection($product->images()->get());
    }

    public function destroy(Product $product, ProductImage $image, ProductImageService $images): Response
    {
        Gate::authorize('manage', $product);

        $images->delete($image);

        return response()->noContent();
    }
}
