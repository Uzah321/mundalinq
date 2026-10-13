<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockMovementController extends Controller
{
    /**
     * The product's stock ledger, newest first.
     */
    public function index(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('manage', $product);

        return InventoryMovementResource::collection(
            $product->movements()->with(['batch', 'actor'])->latest()->orderByDesc('id')->paginate(50)
        );
    }
}
