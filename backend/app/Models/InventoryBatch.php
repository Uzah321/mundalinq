<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A harvest or delivery of stock, kept for traceability. Quantities are in the product's stock unit.
 */
#[Fillable(['code', 'harvested_on', 'best_before', 'quantity_received', 'quantity_on_hand', 'notes', 'created_by'])]
class InventoryBatch extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'harvested_on' => 'date',
            'best_before' => 'date',
            'quantity_received' => 'decimal:3',
            'quantity_on_hand' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
