<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The listing as its farm (and staff) see it: status, review outcome, raw settings and stock detail.
 *
 * @mixin \App\Models\Product
 */
class ManagedProductResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'farm_location_id' => $this->farm_location_id,
            // The listing's own settings; buyers see these combined with the farm's.
            'offers_pickup' => $this->offers_pickup,
            'offers_delivery' => $this->offers_delivery,
            'stock' => [
                'unit' => $this->stockUnit->code,
                'on_hand' => Quantity::format($this->stock_on_hand),
                'reserved' => Quantity::format($this->stock_reserved),
                'available' => Quantity::format($this->availableStock()),
                'low_stock_threshold' => Quantity::format($this->low_stock_threshold),
                'is_low' => $this->isLowStock(),
            ],
            'cover_image' => ProductImageResource::make($this->whenLoaded('coverImage')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function displayedVariants(): Collection
    {
        return $this->variants;
    }
}
