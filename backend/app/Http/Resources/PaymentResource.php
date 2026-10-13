<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'method' => $this->method,
            'provider' => $this->provider,
            'amount' => Money::format($this->amount_minor),
            'currency' => $this->currency,
            'payer_phone' => $this->payer_phone,
            // While pending: what the app shows, e.g. {"type": "approve_on_phone", "message": "..."}.
            'next_action' => $this->status === PaymentStatus::Pending ? ($this->provider_data['next_action'] ?? null) : null,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded' => Money::format((int) $this->refunded_minor),
            // Money that arrived for an order that could not take it; support refunds it.
            'requires_refund' => $this->when((bool) $request->user()?->isStaff(), $this->requires_refund),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
