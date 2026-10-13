<?php

namespace App\Http\Resources;

use App\Models\Dispute;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
class DisputeResource extends JsonResource
{
    /** What the dispute views read; load it to avoid a query per field. */
    public const RELATIONS = ['sellerOrder', 'order', 'farm', 'buyer', 'messages.author', 'evidence', 'refunds'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'description' => $this->description,
            'currency' => $this->order->currency,
            'requested_refund' => $this->requested_refund_minor === null ? null : Money::format($this->requested_refund_minor),
            'resolution' => $this->resolution,
            'refund_amount' => $this->refund_minor === null ? null : Money::format($this->refund_minor),
            'resolution_note' => $this->resolution_note,
            'seller_order' => [
                'id' => $this->sellerOrder->id,
                'number' => $this->sellerOrder->number,
                'total' => Money::format($this->sellerOrder->total_minor),
            ],
            'farm' => ['id' => $this->farm->id, 'name' => $this->farm->name],
            'buyer' => ['name' => $this->buyer->name],
            'messages' => DisputeMessageResource::collection($this->whenLoaded('messages')),
            'evidence' => $this->whenLoaded('evidence', fn () => $this->evidence->map(fn ($photo) => [
                'id' => $photo->id,
                'party' => $photo->uploader_party,
                'url' => route('v1.disputes.evidence.show', [$this->id, $photo->id]),
                'created_at' => $photo->created_at?->toIso8601String(),
            ])),
            'refunds' => $this->when(
                (bool) $request->user()?->isStaff() && $this->relationLoaded('refunds'),
                fn () => RefundResource::collection($this->refunds),
            ),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
