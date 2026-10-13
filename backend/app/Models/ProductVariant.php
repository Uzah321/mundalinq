<?php

namespace App\Models;

use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A way to buy a product: a sale unit (kg, crate...), its price, and how much stock one unit uses.
 */
#[Fillable([
    'unit_id', 'label', 'base_quantity', 'price_minor', 'min_order_quantity', 'max_order_quantity',
    'is_active', 'sort_order',
])]
class ProductVariant extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:3',
            'price_minor' => 'integer',
            'min_order_quantity' => 'decimal:3',
            'max_order_quantity' => 'decimal:3',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(VariantPriceTier::class)->orderBy('min_quantity');
    }

    /**
     * How many of this sale unit current stock can fill: whole crates, or kilograms to the gram.
     */
    public function availableQuantity(): BigDecimal
    {
        $available = $this->product->availableStock();

        if (! $available->isPositive()) {
            return Quantity::of(0);
        }

        return $available
            ->dividedBy($this->base_quantity, $this->unit->allows_fraction ? Quantity::SCALE : 0, RoundingMode::Down)
            ->toScale(Quantity::SCALE);
    }

    /**
     * Price of one sale unit when ordering $quantity: the highest bulk tier reached, else the base price.
     */
    public function unitPriceMinorFor(BigDecimal|string $quantity): int
    {
        $quantity = Quantity::of($quantity);
        $best = null;

        foreach ($this->priceTiers as $tier) {
            if ($quantity->isGreaterThanOrEqualTo($tier->min_quantity)
                && ($best === null || Quantity::of($tier->min_quantity)->isGreaterThan($best->min_quantity))) {
                $best = $tier;
            }
        }

        return $best?->price_minor ?? $this->price_minor;
    }
}
