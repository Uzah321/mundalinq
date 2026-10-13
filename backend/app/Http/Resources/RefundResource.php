<?php

namespace App\Http\Resources;

use App\Models\Refund;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'seller_order_id' => $this->seller_order_id,
            'dispute_id' => $this->dispute_id,
            'amount' => Money::format($this->amount_minor),
            'currency' => $this->currency,
            'status' => $this->status,
            'provider_reference' => $this->provider_reference,
            'failure_reason' => $this->failure_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
