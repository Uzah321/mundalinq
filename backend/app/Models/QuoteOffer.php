<?php

namespace App\Models;

use App\Enums\FulfilmentMethod;
use App\Enums\QuoteFrequency;
use App\Enums\QuoteOfferStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A farm's price for a request, on one of its listings' selling options.
 */
class QuoteOffer extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => QuoteOfferStatus::class,
            'fulfilment' => FulfilmentMethod::class,
            'unit_price_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'valid_until' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryFeeMinor(): int
    {
        return $this->fulfilment === FulfilmentMethod::Delivery ? $this->delivery_fee_minor : 0;
    }

    public function totalMinor(): int
    {
        return Money::multiply($this->unit_price_minor, (string) $this->quantity) + $this->deliveryFeeMinor();
    }

    /**
     * Recurring requests: the buyer orders again at the agreed price until the offer ends.
     */
    public function canOrderAgain(): bool
    {
        return $this->status === QuoteOfferStatus::Accepted
            && $this->quoteRequest->frequency !== QuoteFrequency::OneOff
            && ($this->valid_until === null || $this->valid_until->copy()->endOfDay()->isFuture());
    }
}
