<?php

namespace App\Http\Requests\Farms;

use App\Support\Money;

final class DeliveryFees
{
    /**
     * Convert the client's money strings into stored cents, for whichever fields were sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, int|null>
     */
    public static function attributes(array $validated): array
    {
        $attributes = [];

        if (array_key_exists('delivery_fee', $validated)) {
            $attributes['delivery_fee_minor'] = Money::toMinor($validated['delivery_fee'] ?? '0');
        }

        if (array_key_exists('free_delivery_over', $validated)) {
            $attributes['free_delivery_over_minor'] = $validated['free_delivery_over'] === null ? null : Money::toMinor($validated['free_delivery_over']);
        }

        return $attributes;
    }
}
