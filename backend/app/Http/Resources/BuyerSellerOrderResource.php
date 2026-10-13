<?php

namespace App\Http\Resources;

use App\Enums\SellerOrderStatus;
use App\Models\SellerOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm's part of an order, as the buyer sees it.
 *
 * @mixin SellerOrder
 */
class BuyerSellerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paid = $this->isPaid();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'farm' => ['id' => $this->farm->id, 'name' => $this->farm->name, 'contact_phone' => $this->farm->contact_phone],
            'fulfilment_method' => $this->fulfilment_method,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => Money::format($this->subtotal_minor),
            'delivery_fee' => Money::format($this->delivery_fee_minor),
            'total' => Money::format($this->total_minor),
            'rejection_reason' => $this->rejection_reason,
            'confirm_by' => $this->confirm_by?->toIso8601String(),
            // Shown to the farm at handover, once there is something to hand over.
            'handover_code' => $this->when(
                in_array($this->status, [SellerOrderStatus::Preparing, SellerOrderStatus::Ready, SellerOrderStatus::Dispatched], true),
                fn () => $this->handover_code,
            ),
            'delivery_address' => $this->delivery_address,
            // Exact pickup points only once paid; before that the public listing location is enough.
            'pickup_locations' => $this->when($paid && $this->isPickup() && $this->relationLoaded('items'), fn () => $this->items
                ->map(fn ($item) => $item->product->location)
                ->unique('id')
                ->map(fn ($location) => $location->only(['name', 'province', 'district', 'ward', 'address_line', 'latitude', 'longitude']))
                ->values()),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Until then the buyer can report a problem; the sale becomes final after it.
            'report_problem_until' => $this->when($this->status === SellerOrderStatus::Delivered, fn () => $this->disputeDeadline()->toIso8601String()),
            'dispute' => $this->whenLoaded('dispute', fn () => $this->dispute ? ['id' => $this->dispute->id, 'status' => $this->dispute->status] : null),
            // Null until the buyer reviews it (possible once completed).
            'review' => $this->whenLoaded('review', fn () => $this->review ? ['id' => $this->review->id, 'rating' => $this->review->rating] : null),
        ];
    }
}
