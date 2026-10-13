<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The buyer's order with its farm orders.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** What the buyer's order views read; load it to avoid a query per field. */
    public const RELATIONS = ['sellerOrders.farm', 'sellerOrders.items.product.location', 'sellerOrders.review', 'sellerOrders.dispute', 'payments'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'cancellation_reason' => $this->cancellation_reason,
            'currency' => $this->currency,
            'subtotal' => Money::format($this->subtotal_minor),
            'delivery_fee' => Money::format($this->delivery_fee_minor),
            'total' => Money::format($this->total_minor),
            'note' => $this->note,
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'seller_orders' => BuyerSellerOrderResource::collection($this->whenLoaded('sellerOrders')),
            'latest_payment' => $this->whenLoaded('payments', fn () => $this->payments->isEmpty() ? null : PaymentResource::make($this->payments->first())),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
