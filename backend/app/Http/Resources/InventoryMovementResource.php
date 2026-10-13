<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\InventoryMovement
 */
class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            // Signed: "-5" removed five units, "120" added 120.
            'quantity' => Quantity::format($this->quantity),
            'balance_after' => Quantity::format($this->balance_after),
            'note' => $this->note,
            'batch' => $this->batch ? ['id' => $this->batch->id, 'code' => $this->batch->code] : null,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
