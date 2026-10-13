<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\StockAdjustmentDirection;
use App\Enums\StockAdjustmentReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Services\Inventory\InventoryService;

class InventoryAdjustmentController extends Controller
{
    /**
     * Correct a batch's count or record a loss. The movement's balance_after is the new stock on hand.
     */
    public function store(AdjustStockRequest $request, InventoryService $inventory): InventoryMovementResource
    {
        $movement = $inventory->adjust(
            $request->batch(),
            $request->user(),
            StockAdjustmentDirection::from($request->validated('direction')),
            (string) $request->validated('quantity'),
            StockAdjustmentReason::from($request->validated('reason')),
            $request->validated('note'),
        );

        return new InventoryMovementResource($movement->load(['batch', 'actor']));
    }
}
