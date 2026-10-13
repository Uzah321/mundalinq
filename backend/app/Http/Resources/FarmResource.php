<?php

namespace App\Http\Resources;

use App\Models\Farm;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Farm
 */
class FarmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'farmer_type' => $this->farmer_type,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'country_code' => $this->country_code,
            'offers_pickup' => $this->offers_pickup,
            'offers_delivery' => $this->offers_delivery,
            'delivery_radius_km' => $this->delivery_radius_km === null ? null : (float) $this->delivery_radius_km,
            'delivery_fee' => Money::format($this->delivery_fee_minor ?? 0),
            'free_delivery_over' => $this->free_delivery_over_minor === null ? null : Money::format($this->free_delivery_over_minor),
            'verification_status' => $this->verification_status,
            'verified' => $this->isApproved(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'rating' => ['average' => $this->rating_average, 'count' => (int) $this->rating_count],
            'primary_location' => FarmLocationResource::make($this->whenLoaded('primaryLocation')),
            'locations' => FarmLocationResource::collection($this->whenLoaded('locations')),
            // Present when the farm was loaded through the user's memberships.
            'my_role' => $this->whenPivotLoaded('farm_members', fn () => $this->pivot->role),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
