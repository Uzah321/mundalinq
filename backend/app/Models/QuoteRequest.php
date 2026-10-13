<?php

namespace App\Models;

use App\Enums\QuoteFrequency;
use App\Enums\QuoteRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer asking farms for a price, once or on a schedule ("200 kg of tomatoes every Friday").
 */
class QuoteRequest extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'frequency' => QuoteFrequency::class,
            'status' => QuoteRequestStatus::class,
            'needed_by' => 'date',
            'closes_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(QuoteOffer::class);
    }

    /**
     * Still taking offers.
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', QuoteRequestStatus::Open)->where('closes_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === QuoteRequestStatus::Open && $this->closes_at->isFuture();
    }
}
