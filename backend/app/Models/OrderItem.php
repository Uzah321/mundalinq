<?php

namespace App\Models;

use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a bought item. Written only by the checkout and order services.
 */
class OrderItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'confirmed_quantity' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'stock_quantity' => 'decimal:3',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * What the farm supplies: the confirmed quantity once accepted, otherwise what was ordered.
     */
    public function finalQuantity(): BigDecimal
    {
        return Quantity::of($this->confirmed_quantity ?? $this->quantity);
    }
}
