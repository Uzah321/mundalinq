<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'title' => $this->product_title,
            'label' => $this->variant_label,
            'unit' => ['code' => $this->unit_code, 'name' => $this->unit_name, 'plural_name' => $this->unit_plural_name],
            'quantity' => Quantity::format($this->quantity),
            // Set once the farm accepts; may be less than ordered (0 = could not supply).
            'confirmed_quantity' => Quantity::format($this->confirmed_quantity),
            'unit_price' => Money::format($this->unit_price_minor),
            'line_total' => Money::format($this->line_total_minor),
        ];
    }
}
