<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full listing as buyers see it. The farm's own view is ManagedProductResource.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Relations the detail views read; load them to avoid a query per field. */
    public const RELATIONS = ['farm', 'location', 'category', 'stockUnit', 'images', 'variants.unit', 'variants.priceTiers'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
            'quality_grade' => $this->quality_grade,
            'featured' => $this->isFeatured(),
            'currency' => $this->currency,
            'stock_unit' => UnitResource::make($this->stockUnit),
            'available_quantity' => Quantity::format($this->availableStock()),
            'variants' => ProductVariantResource::collection($this->displayedVariants()),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'farm' => [
                'id' => $this->farm->id,
                'name' => $this->farm->name,
                'verified' => $this->farm->isApproved(),
                'contact_phone' => $this->farm->contact_phone,
            ],
            'location' => [
                'province' => $this->location->province,
                'district' => $this->location->district,
                'ward' => $this->location->ward,
            ],
            'offers_pickup' => $this->offersPickupToBuyers(),
            'offers_delivery' => $this->offersDeliveryToBuyers(),
            'delivery_radius_km' => $this->offersDeliveryToBuyers() ? (float) $this->farm->delivery_radius_km : null,
            'last_harvested_on' => $this->last_harvested_on?->toDateString(),
            'rating' => ['average' => $this->rating_average, 'count' => (int) $this->rating_count],
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    protected function displayedVariants(): Collection
    {
        return $this->variants->where('is_active', true)->values();
    }
}
