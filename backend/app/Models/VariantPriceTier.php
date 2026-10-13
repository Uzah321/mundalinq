<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulk price: ordering at least min_quantity sale units makes each one cost price_minor.
 */
#[Fillable(['min_quantity', 'price_minor'])]
class VariantPriceTier extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price_minor' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
