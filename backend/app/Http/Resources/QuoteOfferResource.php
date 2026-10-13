<?php

namespace App\Http\Resources;

use App\Models\QuoteOffer;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm's price for a request. Load variant.product, variant.unit, farm and quoteRequest.
 *
 * @mixin QuoteOffer
 */
class QuoteOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->variant;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'farm' => $this->whenLoaded('farm', fn () => [
                'id' => $this->farm->id,
                'name' => $this->farm->name,
                'verified' => $this->farm->isApproved(),
                'rating' => ['average' => $this->farm->rating_average, 'count' => (int) $this->farm->rating_count],
            ]),
            'listing' => [
                'product_id' => $variant->product_id,
                'title' => $variant->product->title,
                'variant_id' => $variant->id,
                'label' => $variant->label,
                'unit' => UnitResource::make($variant->unit),
            ],
            'currency' => $this->currency,
            'unit_price' => Money::format($this->unit_price_minor),
            'quantity' => Quantity::format((string) $this->quantity),
            'fulfilment' => $this->fulfilment,
            'delivery_fee' => Money::format($this->deliveryFeeMinor()),
            'total' => Money::format($this->totalMinor()),
            'message' => $this->message,
            'valid_until' => $this->valid_until?->toDateString(),
            'can_order_again' => $this->relationLoaded('quoteRequest') && $this->canOrderAgain(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
