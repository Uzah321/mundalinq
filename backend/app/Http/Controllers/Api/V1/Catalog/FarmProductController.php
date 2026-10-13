<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A farm's own listings in every status, for the farmer app's Products tab.
 */
class FarmProductController extends Controller
{
    public function index(Request $request, Farm $farm): AnonymousResourceCollection
    {
        Gate::authorize('update', $farm);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $products = $farm->products()
            ->with(['farm', 'location', 'category', 'stockUnit', 'coverImage', 'variants.unit', 'variants.priceTiers'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('low_stock'), fn ($query) => $query
                ->whereNotNull('low_stock_threshold')
                ->whereRaw('stock_on_hand - stock_reserved <= low_stock_threshold'))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ManagedProductResource::collection($products);
    }
}
