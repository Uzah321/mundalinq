<?php

namespace App\Http\Resources;

use App\Enums\QuoteRequestStatus;
use App\Models\QuoteRequest;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A request for quotes. Farms see the buyer's first name only; contact details come with an order.
 *
 * @mixin QuoteRequest
 */
class QuoteRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isBuyer = $request->user()?->id === $this->buyer_id;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'quantity' => Quantity::format((string) $this->quantity),
            'unit' => UnitResource::make($this->unit),
            'frequency' => $this->frequency,
            'frequency_label' => $this->frequency->label(),
            'needed_by' => $this->needed_by->toDateString(),
            'fulfilment' => $this->fulfilment,
            'province' => $this->province,
            'district' => $this->district,
            'notes' => $this->notes,
            // "expired" when the closing time passed without a decision.
            'status' => $this->status === QuoteRequestStatus::Open && ! $this->isOpen() ? 'expired' : $this->status->value,
            'closes_at' => $this->closes_at->toIso8601String(),
            'buyer' => $this->whenLoaded('buyer', fn () => ['name' => $isBuyer ? $this->buyer->name : Str::before($this->buyer->name, ' ')]),
            'offers_count' => $this->whenCounted('offers'),
            'offers' => QuoteOfferResource::collection($this->whenLoaded('offers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
