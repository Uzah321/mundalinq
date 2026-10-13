<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\FarmLocation
 */
class FarmLocationResource extends JsonResource
{
    private const EXACT_COORDINATES = 'farm_locations.exact_coordinates';

    /**
     * Show exact coordinates in this response (farm members and staff). Everyone else sees them
     * rounded to about a kilometre, so a homestead cannot be pinpointed from a public listing.
     */
    public static function revealExactCoordinates(Request $request): void
    {
        $request->attributes->set(self::EXACT_COORDINATES, true);
    }

    public function toArray(Request $request): array
    {
        $exact = $request->attributes->getBoolean(self::EXACT_COORDINATES);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'province' => $this->province,
            'district' => $this->district,
            'ward' => $this->ward,
            'address_line' => $exact ? $this->address_line : null,
            'latitude' => $exact ? $this->latitude : round($this->latitude, 2),
            'longitude' => $exact ? $this->longitude : round($this->longitude, 2),
            'is_primary' => $this->is_primary,
        ];
    }
}
