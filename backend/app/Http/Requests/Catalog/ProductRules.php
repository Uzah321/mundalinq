<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use App\Models\Farm;
use App\Models\Unit;
use App\Support\Quantity;
use Illuminate\Support\MessageBag;

/**
 * Product checks shared by create and update that need the database or several fields.
 */
final class ProductRules
{
    /**
     * A listing can only offer pickup or delivery if its farm does, and must offer at least one.
     */
    public static function checkFulfilment(MessageBag $errors, Farm $farm, bool $pickup, bool $delivery): void
    {
        if ($pickup && ! $farm->offers_pickup) {
            $errors->add('offers_pickup', __('Your farm does not offer pickup. Turn it on in your farm settings first.'));
        }

        if ($delivery && ! $farm->offers_delivery) {
            $errors->add('offers_delivery', __('Your farm does not offer delivery. Turn it on in your farm settings first.'));
        }

        if (! $pickup && ! $delivery) {
            $errors->add('offers_pickup', __('Offer at least pickup or delivery.'));
        }
    }

    /**
     * Products are listed in the most specific category available ("Tomatoes", not "Vegetables").
     */
    public static function checkCategory(MessageBag $errors, string $categoryId): void
    {
        if (Category::findOrFail($categoryId)->hasActiveChildren()) {
            $errors->add('category_id', __('Choose a more specific category.'));
        }
    }

    public static function checkLocation(MessageBag $errors, Farm $farm, ?string $locationId): void
    {
        if ($locationId !== null && ! $farm->locations()->whereKey($locationId)->exists()) {
            $errors->add('farm_location_id', __('This location does not belong to the farm.'));
        }
    }

    /**
     * Stock counted in heads, crates or animals cannot be fractional.
     *
     * @param  array<string, mixed>  $quantities  Field => value (null values are skipped).
     */
    public static function checkWholeStock(MessageBag $errors, Unit $stockUnit, array $quantities): void
    {
        if ($stockUnit->allows_fraction) {
            return;
        }

        foreach ($quantities as $field => $value) {
            if ($value !== null && ! Quantity::isWhole(Quantity::of($value))) {
                $errors->add($field, __('Stock is counted in whole :units.', ['units' => $stockUnit->plural_name]));
            }
        }
    }
}
