<?php

namespace App\Http\Resources;

use App\Enums\SellerOrderStatus;
use App\Models\SellerOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm's order as the farm sees it: a packing list. Never includes the buyer's handover code.
 *
 * @mixin SellerOrder
 */
class FarmSellerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The buyer's contact details are shared once the farm has committed to the order.
        $committed = ! in_array($this->status, [
            SellerOrderStatus::AwaitingConfirmation, SellerOrderStatus::Rejected,
            SellerOrderStatus::Expired, SellerOrderStatus::Cancelled,
        ], true);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'farm_id' => $this->farm_id,
            'fulfilment_method' => $this->fulfilment_method,
            'buyer' => [
                'name' => $this->order->buyer->name,
                'phone' => $committed ? $this->order->buyer->phone : null,
            ],
            'note' => $this->order->note,
            'delivery_address' => $committed ? $this->delivery_address : null,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => Money::format($this->subtotal_minor),
            'delivery_fee' => Money::format($this->delivery_fee_minor),
            'total' => Money::format($this->total_minor),
            'currency' => $this->order->currency,
            'rejection_reason' => $this->rejection_reason,
            'confirm_by' => $this->confirm_by?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'dispute' => $this->whenLoaded('dispute', fn () => $this->dispute ? ['id' => $this->dispute->id, 'status' => $this->dispute->status] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
