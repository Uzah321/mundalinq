<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectProductRequest;
use App\Http\Resources\ManagedProductResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductModerationController extends Controller
{
    /**
     * Review queue; defaults to listings waiting for review, longest-waiting first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = ProductStatus::from($filters['status'] ?? ProductStatus::PendingReview->value);

        $products = Product::query()
            ->where('status', $status)
            ->with(ProductResource::RELATIONS)
            ->when($status === ProductStatus::PendingReview, fn ($query) => $query->oldest('updated_at'), fn ($query) => $query->latest('updated_at'))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ManagedProductResource::collection($products);
    }

    public function approve(Request $request, Product $product, ProductLifecycle $lifecycle): ManagedProductResource
    {
        $product = $lifecycle->approve($product, $request->user());

        return new ManagedProductResource($product->load(ProductResource::RELATIONS));
    }

    /**
     * Refuse a listing under review, or take down a live one. The farmer is told why by SMS.
     */
    public function reject(RejectProductRequest $request, Product $product, ProductLifecycle $lifecycle): ManagedProductResource
    {
        $product = $lifecycle->reject($product, $request->user(), $request->validated('reason'));

        return new ManagedProductResource($product->load(ProductResource::RELATIONS));
    }
}
