<?php

namespace App\Http\Requests\Catalog;

use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

/**
 * Shape rules for a selling option. Rules spanning several fields live in VariantSpec.
 */
final class VariantRules
{
    public static function for(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            $prefix.'unit' => [...$required, 'string', Rule::exists('units', 'code')],
            $prefix.'label' => ['sometimes', 'nullable', 'string', 'max:60'],
            $prefix.'base_quantity' => ['sometimes', DecimalString::quantity()],
            $prefix.'price' => [...$required, DecimalString::money()],
            $prefix.'min_order_quantity' => ['sometimes', DecimalString::quantity()],
            $prefix.'max_order_quantity' => ['sometimes', 'nullable', DecimalString::quantity()],
            $prefix.'price_tiers' => ['sometimes', 'nullable', 'array', 'max:'.config('mundalinq.catalog.max_price_tiers')],
            $prefix.'price_tiers.*.min_quantity' => ['required', DecimalString::quantity()],
            $prefix.'price_tiers.*.price' => ['required', DecimalString::money()],
            $prefix.'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
