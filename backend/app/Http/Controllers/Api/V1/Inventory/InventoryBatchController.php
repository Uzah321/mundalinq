<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Resources\InventoryBatchResource;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InventoryBatchController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('manage', $product);

        return InventoryBatchResource::collection(
            $product->batches()->orderByDesc('created_at')->orderByDesc('id')->paginate(50)
        );
    }

    /**
     * Record a new harvest or delivery of stock.
     */
    public function store(ReceiveStockRequest $request, Product $product, InventoryService $inventory): InventoryBatchResource
    {
        $batch = $inventory->receive(
            $product,
            $request->user(),
            (string) $request->validated('quantity'),
            $request->validated('harvested_on'),
            $request->validated('best_before'),
            $request->validated('notes'),
        );

        return new InventoryBatchResource($batch);
    }
}
