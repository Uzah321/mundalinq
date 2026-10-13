<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Written only by PayoutService.
 */
class Payout extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'method' => PayoutMethod::class,
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class)->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
