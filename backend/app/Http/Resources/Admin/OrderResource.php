<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BuyerSellerOrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order with everything support needs: buyer, farms, payments, refunds and its status history.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** The detail view; the list loads only buyer and seller orders. */
    public const RELATIONS = [
        'buyer', 'sellerOrders.farm', 'sellerOrders.items.product.location', 'sellerOrders.review',
        'sellerOrders.dispute', 'payments', 'refunds', 'history.actor',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'cancellation_reason' => $this->cancellation_reason,
            'buyer' => ['id' => $this->buyer->id, 'name' => $this->buyer->name, 'phone' => $this->buyer->phone],
            'currency' => $this->currency,
            'subtotal' => Money::format($this->subtotal_minor),
            'delivery_fee' => Money::format($this->delivery_fee_minor),
            'total' => Money::format($this->total_minor),
            'note' => $this->note,
            'seller_orders' => BuyerSellerOrderResource::collection($this->whenLoaded('sellerOrders')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($change) => [
                'seller_order_id' => $change->seller_order_id,
                'from' => $change->from_status,
                'to' => $change->to_status,
                'actor' => $change->actor?->name,
                'note' => $change->note,
                'at' => $change->created_at?->toIso8601String(),
            ])),
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
