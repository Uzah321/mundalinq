<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buyer-facing product browsing: live, in-stock listings from verified farms.
 *
 * Distance uses the haversine formula on plain coordinates with a bounding-box prefilter, which is
 * plenty for the pilot. Swap for PostGIS (ST_DWithin) when radius search needs to scale.
 */
class ProductSearch
{
    private const EARTH_RADIUS_KM = 6371;

    private const KM_PER_DEGREE = 111.045;

    /**
     * @param  array<string, mixed>  $filters  Validated SearchProductsRequest input.
     */
    public function paginate(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $query = Product::query()
            ->select('products.*')
            ->join('farm_locations', 'farm_locations.id', '=', 'products.farm_location_id')
            ->visibleToBuyers()
            ->inStock()
            ->addSelect(['from_price_minor' => ProductVariant::query()
                ->selectRaw('min(price_minor)')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('is_active', true),
            ])
            ->with(self::cardRelations())
            ->when($viewer, fn (Builder $query) => $query->withExists([
                'favourites as is_favourite' => fn (Builder $favourite) => $favourite->where('user_id', $viewer->id),
            ]));

        $this->applyFilters($query, $filters);

        $nearby = isset($filters['lat'], $filters['lng']);

        if ($nearby) {
            $this->applyRadius($query, (float) $filters['lat'], (float) $filters['lng'], (float) ($filters['radius_km'] ?? 50));
        }

        match ($filters['sort'] ?? ($nearby ? 'nearest' : 'newest')) {
            'nearest' => $query->orderBy('distance_km'),
            'price_asc' => $query->orderBy('from_price_minor'),
            'price_desc' => $query->orderByDesc('from_price_minor'),
            'freshest' => $query->orderByRaw('products.last_harvested_on desc nulls last'),
            default => $query->orderByDesc('products.published_at'),
        };

        return $query->orderBy('products.id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * What a product card (ProductCardResource) reads; load it to avoid a query per field.
     *
     * @return array<int|string, string|\Closure>
     */
    public static function cardRelations(): array
    {
        return [
            'farm',
            'location',
            'category',
            'stockUnit',
            'coverImage',
            'variants' => fn ($variants) => $variants->where('is_active', true)->with('unit'),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $match) => $match
                ->whereLike('products.title', $term)
                ->orWhereLike('products.description', $term)
                ->orWhereHas('category', fn (Builder $category) => $category->whereLike('name', $term))
                ->orWhereHas('farm', fn (Builder $farm) => $farm->whereLike('name', $term)));
        }

        if (isset($filters['category'])) {
            $category = Category::where('slug', $filters['category'])->firstOrFail();
            $query->whereIn('products.category_id', $category->selfAndChildIds());
        }

        if (isset($filters['farm_id'])) {
            $query->where('products.farm_id', $filters['farm_id']);
        }

        if (isset($filters['province'])) {
            $query->where('farm_locations.province', $filters['province']);
        }

        if (isset($filters['district'])) {
            $query->whereLike('farm_locations.district', addcslashes($filters['district'], '%_\\'));
        }

        if (isset($filters['grade'])) {
            $query->where('products.quality_grade', $filters['grade']);
        }

        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $query->whereHas('variants', function (Builder $variant) use ($filters) {
                $variant->where('is_active', true)
                    ->when(isset($filters['min_price']), fn ($q) => $q->where('price_minor', '>=', Money::toMinor($filters['min_price'])))
                    ->when(isset($filters['max_price']), fn ($q) => $q->where('price_minor', '<=', Money::toMinor($filters['max_price'])));
            });
        }

        match ($filters['fulfilment'] ?? null) {
            'pickup' => $query->where('products.offers_pickup', true)->whereHas('farm', fn (Builder $farm) => $farm->where('offers_pickup', true)),
            'delivery' => $query->where('products.offers_delivery', true)->whereHas('farm', fn (Builder $farm) => $farm->where('offers_delivery', true)),
            default => null,
        };
    }

    private function applyRadius(Builder $query, float $lat, float $lng, float $radiusKm): void
    {
        $distance = 'CAST(? AS double precision) * 2 * asin(least(1, sqrt('
            .'power(sin(radians(CAST(farm_locations.latitude AS double precision) - CAST(? AS double precision)) / 2), 2)'
            .' + cos(radians(CAST(? AS double precision))) * cos(radians(CAST(farm_locations.latitude AS double precision)))'
            .' * power(sin(radians(CAST(farm_locations.longitude AS double precision) - CAST(? AS double precision)) / 2), 2))))';
        $bindings = [self::EARTH_RADIUS_KM, $lat, $lat, $lng];

        // Cheap rectangle first, so the trigonometry only runs on nearby farms.
        $latDelta = $radiusKm / self::KM_PER_DEGREE;
        $lngDelta = $radiusKm / (self::KM_PER_DEGREE * max(cos(deg2rad($lat)), 0.01));

        $query->selectRaw("{$distance} as distance_km", $bindings)
            ->whereBetween('farm_locations.latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('farm_locations.longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->whereRaw("{$distance} <= ?", [...$bindings, $radiusKm]);
    }
}
