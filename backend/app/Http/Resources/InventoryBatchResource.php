<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\InventoryBatch
 */
class InventoryBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'harvested_on' => $this->harvested_on?->toDateString(),
            'best_before' => $this->best_before?->toDateString(),
            'quantity_received' => Quantity::format($this->quantity_received),
            'quantity_on_hand' => Quantity::format($this->quantity_on_hand),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
