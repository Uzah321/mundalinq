<?php

namespace App\Services\Farms;

use App\Enums\FarmMemberRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Farm;
use App\Models\FarmLocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FarmService
{
    /**
     * Create a farm with its primary location; the creator becomes its owner.
     */
    public function create(User $owner, array $attributes, array $location): Farm
    {
        return DB::transaction(function () use ($owner, $attributes, $location) {
            $farm = Farm::create($attributes);
            $farm->members()->attach($owner->id, ['role' => FarmMemberRole::Owner->value]);
            $farm->locations()->create([...$location, 'is_primary' => true]);

            return $farm;
        });
    }

    public function addLocation(Farm $farm, array $attributes): FarmLocation
    {
        return DB::transaction(function () use ($farm, $attributes) {
            Farm::whereKey($farm->id)->lockForUpdate()->first();

            if ($farm->locations()->count() >= config('mundalinq.farms.max_locations')) {
                throw new BusinessRuleException(__('A farm can have at most :max locations.', [
                    'max' => config('mundalinq.farms.max_locations'),
                ]));
            }

            $location = $farm->locations()->create([...$attributes, 'is_primary' => false]);

            if ($attributes['is_primary'] ?? false) {
                $this->makePrimary($location);
            }

            return $location;
        });
    }

    public function updateLocation(FarmLocation $location, array $attributes): FarmLocation
    {
        if (array_key_exists('is_primary', $attributes) && ! $attributes['is_primary'] && $location->is_primary) {
            throw new BusinessRuleException(__('Choose another primary location instead of unsetting this one.'), 422);
        }

        return DB::transaction(function () use ($location, $attributes) {
            $location->update(collect($attributes)->except('is_primary')->all());

            if ($attributes['is_primary'] ?? false) {
                $this->makePrimary($location);
            }

            return $location->refresh();
        });
    }

    public function deleteLocation(FarmLocation $location): void
    {
        if ($location->is_primary) {
            throw new BusinessRuleException(__('The primary location cannot be removed. Make another location primary first.'));
        }

        if ($location->products()->exists()) {
            throw new BusinessRuleException(__('Some listings are stocked at this location. Move them to another location first.'));
        }

        DB::transaction(function () use ($location) {
            // Archived listings keep their history; point them at the primary location instead.
            Product::onlyTrashed()
                ->where('farm_location_id', $location->id)
                ->update(['farm_location_id' => FarmLocation::where('farm_id', $location->farm_id)->where('is_primary', true)->value('id')]);

            $location->delete();
        });
    }

    private function makePrimary(FarmLocation $location): void
    {
        FarmLocation::where('farm_id', $location->farm_id)
            ->whereKeyNot($location->id)
            ->update(['is_primary' => false]);

        $location->forceFill(['is_primary' => true])->save();
    }
}
