<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact listing for search results and product grids.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cheapest = $this->variants->sortBy('price_minor')->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => ['name' => $this->category->name, 'slug' => $this->category->slug],
            'quality_grade' => $this->quality_grade,
            'currency' => $this->currency,
            'from_price' => $cheapest ? [
                'price' => Money::format($cheapest->price_minor),
                'unit' => $cheapest->unit->code,
                'unit_name' => $cheapest->unit->name,
            ] : null,
            'available_quantity' => Quantity::format($this->availableStock()),
            'stock_unit' => $this->stockUnit->code,
            'cover_image' => ProductImageResource::make($this->whenLoaded('coverImage')),
            'farm' => ['id' => $this->farm->id, 'name' => $this->farm->name, 'verified' => $this->farm->isApproved()],
            'location' => ['province' => $this->location->province, 'district' => $this->location->district],
            // Whole kilometres: precise distances would let someone triangulate a farm's exact position.
            'distance_km' => $this->when(isset($this->distance_km), fn () => max(1, (int) round((float) $this->distance_km))),
            'offers_pickup' => $this->offersPickupToBuyers(),
            'offers_delivery' => $this->offersDeliveryToBuyers(),
            'last_harvested_on' => $this->last_harvested_on?->toDateString(),
            'rating' => ['average' => $this->rating_average, 'count' => (int) $this->rating_count],
            // Only for signed-in buyers (see ProductSearch).
            'is_favourite' => $this->when(isset($this->is_favourite), fn () => (bool) $this->is_favourite),
        ];
    }
}
