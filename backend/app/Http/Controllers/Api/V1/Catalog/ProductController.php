<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchProductsRequest;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ManagedProductResource;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductSearch;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * Public browse and search of live listings.
     */
    public function index(SearchProductsRequest $request, ProductSearch $search): AnonymousResourceCollection
    {
        // Public route: a token is optional; with one, cards say which listings the buyer saved.
        return ProductCardResource::collection($search->paginate($request->validated(), $request->user('sanctum')));
    }

    public function show(Request $request, Product $product): ProductResource
    {
        // Public route: the token is optional, so resolve the user through the Sanctum guard directly.
        $user = $request->user('sanctum');
        Gate::forUser($user)->authorize('view', $product);

        $product->load(ProductResource::RELATIONS);

        return $user && ($user->isStaff() || $product->farm->memberRole($user) !== null)
            ? new ManagedProductResource($product)
            : new ProductResource($product);
    }

    /**
     * New listings start as drafts; publishing is a separate step (see ProductLifecycleController).
     */
    public function store(StoreProductRequest $request, ProductService $products): JsonResponse
    {
        $product = $products->create(
            $request->farm(),
            $request->user(),
            $request->productAttributes(),
            $request->variantSpecs(),
            $request->initialStock(),
        );

        return (new ManagedProductResource($product->load(ProductResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $products): ManagedProductResource
    {
        $product = $products->update($product, $request->validated());

        return new ManagedProductResource($product->load(ProductResource::RELATIONS));
    }

    /**
     * Archive: the listing disappears but stays in the database for order history.
     */
    public function destroy(Product $product): Response
    {
        Gate::authorize('manage', $product);

        $product->delete();

        return response()->noContent();
    }
}
