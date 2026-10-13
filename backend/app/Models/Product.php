<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\QualityGrade;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A listing: one product from one farm location, sold through one or more variants (selling options).
 * Stock is tracked here in the stock unit; status, stock and review fields are only changed by services.
 */
#[Fillable([
    'category_id', 'farm_location_id', 'stock_unit_id', 'title', 'description', 'quality_grade',
    'currency', 'offers_pickup', 'offers_delivery', 'low_stock_threshold',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'stock_on_hand' => '0',
        'stock_reserved' => '0',
        'offers_pickup' => true,
        'offers_delivery' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'quality_grade' => QualityGrade::class,
            'offers_pickup' => 'boolean',
            'offers_delivery' => 'boolean',
            'stock_on_hand' => 'decimal:3',
            'stock_reserved' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'last_harvested_on' => 'date',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'featured_until' => 'datetime',
            'rating_average' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(FarmLocation::class, 'farm_location_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('created_at')->chaperone();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * Positions are kept contiguous from 0, so the cover is always position 0.
     */
    public function coverImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('position', 0);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class);
    }

    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class, 'review_products');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Live listings from verified farms. Callers joining other tables get qualified columns.
     */
    public function scopeVisibleToBuyers(Builder $query): void
    {
        $query->where('products.status', ProductStatus::Active)
            ->where('products.expires_at', '>', now())
            ->whereHas('farm', fn (Builder $farm) => $farm->approved());
    }

    public function scopeInStock(Builder $query): void
    {
        $query->whereColumn('products.stock_on_hand', '>', 'products.stock_reserved')
            ->whereHas('variants', fn (Builder $variant) => $variant->where('is_active', true));
    }

    public function isFeatured(): bool
    {
        return $this->featured_until !== null && $this->featured_until->isFuture();
    }

    public function isVisibleToBuyers(): bool
    {
        return $this->status === ProductStatus::Active
            && ! $this->isExpired()
            && $this->farm !== null
            && ! $this->farm->trashed()
            && $this->farm->isApproved();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Stock that new orders can still claim, in the stock unit.
     */
    public function availableStock(): BigDecimal
    {
        return Quantity::of($this->stock_on_hand)->minus($this->stock_reserved);
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_threshold !== null
            && $this->availableStock()->isLessThanOrEqualTo($this->low_stock_threshold);
    }

    /**
     * A product can only offer what its farm currently offers.
     */
    public function offersPickupToBuyers(): bool
    {
        return $this->offers_pickup && (bool) $this->farm?->offers_pickup;
    }

    public function offersDeliveryToBuyers(): bool
    {
        return $this->offers_delivery && (bool) $this->farm?->offers_delivery;
    }
}
