<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prices and quantities are decimal strings so clients never round them through floats.
 *
 * @mixin \App\Models\ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit' => [
                'code' => $this->unit->code,
                'name' => $this->unit->name,
                'plural_name' => $this->unit->plural_name,
            ],
            'label' => $this->label,
            // Stock units one sale unit uses, e.g. a crate holding 28 kg.
            'base_quantity' => Quantity::format($this->base_quantity),
            'price' => Money::format($this->price_minor),
            'price_tiers' => $this->priceTiers->map(fn ($tier) => [
                'min_quantity' => Quantity::format($tier->min_quantity),
                'price' => Money::format($tier->price_minor),
            ]),
            'min_order_quantity' => Quantity::format($this->min_order_quantity),
            'max_order_quantity' => Quantity::format($this->max_order_quantity),
            'available_quantity' => Quantity::format($this->availableQuantity()),
            'is_active' => $this->is_active,
        ];
    }
}
