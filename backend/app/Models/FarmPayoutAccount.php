<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Written only by PayoutService, which audits every change.
 */
#[Hidden(['account_number'])]
class FarmPayoutAccount extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'method' => PayoutMethod::class,
            'account_number' => 'encrypted',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function lastFour(): string
    {
        return substr((string) $this->account_number, -4);
    }

    /**
     * Payouts wait a while after a change, so a hijacked account cannot be emptied at once.
     */
    public function isOnHold(): bool
    {
        return $this->updated_at !== null
            && $this->updated_at->isAfter(now()->subHours(config('mundalinq.payouts.account_change_hold_hours')));
    }
}
