<?php

namespace App\Models;

use App\Enums\FarmerType;
use App\Enums\FarmMemberRole;
use App\Enums\FarmVerificationStatus;
use Database\Factories\FarmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'description', 'farmer_type', 'contact_phone', 'contact_email',
    'offers_pickup', 'offers_delivery', 'delivery_radius_km', 'delivery_fee_minor', 'free_delivery_over_minor',
])]
class Farm extends Model
{
    /** @use HasFactory<FarmFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'country_code' => 'ZW',
        'offers_pickup' => true,
        'offers_delivery' => false,
        'delivery_fee_minor' => 0,
        'verification_status' => 'unverified',
    ];

    protected function casts(): array
    {
        return [
            'farmer_type' => FarmerType::class,
            'verification_status' => FarmVerificationStatus::class,
            'offers_pickup' => 'boolean',
            'offers_delivery' => 'boolean',
            'delivery_radius_km' => 'decimal:2',
            'delivery_fee_minor' => 'integer',
            'free_delivery_over_minor' => 'integer',
            'verified_at' => 'datetime',
            'rating_average' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_members')->withPivot('role')->withTimestamps();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FarmLocation::class)->orderByDesc('is_primary')->orderBy('created_at');
    }

    public function primaryLocation(): HasOne
    {
        return $this->hasOne(FarmLocation::class)->where('is_primary', true);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(FarmVerification::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function payoutAccount(): HasOne
    {
        return $this->hasOne(FarmPayoutAccount::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * Owners: they get payout and payout-account alerts.
     */
    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('role', FarmMemberRole::Owner->value);
    }

    /**
     * Flat delivery charge for an order with this goods value, waived above the farm's threshold.
     */
    public function deliveryFeeFor(int $subtotalMinor): int
    {
        return $this->free_delivery_over_minor !== null && $subtotalMinor >= $this->free_delivery_over_minor
            ? 0
            : $this->delivery_fee_minor;
    }

    public function latestVerification(): ?FarmVerification
    {
        // UUIDv7 ids are time-ordered, which breaks ties between submissions in the same second.
        return $this->verifications()->latest()->orderByDesc('id')->first();
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('verification_status', FarmVerificationStatus::Approved);
    }

    public function isApproved(): bool
    {
        return $this->verification_status === FarmVerificationStatus::Approved;
    }

    public function memberRole(User $user): ?FarmMemberRole
    {
        $role = $this->members()->whereKey($user->id)->value('farm_members.role');

        return $role ? FarmMemberRole::from($role) : null;
    }
}
