<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two levels: top-level groups (Vegetables) and the categories products are listed in (Tomatoes).
 * A top-level category without children (Honey) is also listable.
 */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'requires_approval', 'is_active', 'sort_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function hasActiveChildren(): bool
    {
        return $this->children()->where('is_active', true)->exists();
    }

    /**
     * Subcategories inherit review requirements from their group (every kind of livestock is reviewed).
     */
    public function requiresApproval(): bool
    {
        return $this->requires_approval || (bool) $this->parent?->requires_approval;
    }

    /**
     * This category and its subcategories, for "everything under Vegetables" filters.
     *
     * @return list<string>
     */
    public function selfAndChildIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }
}
