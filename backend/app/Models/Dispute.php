<?php

namespace App\Models;

use App\Enums\DisputeParty;
use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Written only by DisputeService.
 */
class Dispute extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'requested_refund_minor' => 'integer',
            'refund_minor' => 'integer',
            'resolved_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class)->withTrashed();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class)->orderBy('created_at')->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DisputeStatus::Open;
    }

    /**
     * How the user takes part: as the buyer, for the farm, or as support staff. Null for anyone else.
     */
    public function partyOf(User $user): ?DisputeParty
    {
        return match (true) {
            $this->buyer_id === $user->id => DisputeParty::Buyer,
            $this->farm->memberRole($user) !== null => DisputeParty::Farm,
            $user->isStaff() => DisputeParty::Staff,
            default => null,
        };
    }
}
