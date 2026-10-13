<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\QualityGrade;
use App\Models\Category;
use App\Models\Farm;
use App\Models\FarmLocation;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            // farm_id must come before farm_location_id, which is resolved from it.
            'farm_id' => Farm::factory()->approved(),
            'farm_location_id' => fn (array $attributes) => FarmLocation::where('farm_id', $attributes['farm_id'])
                ->where('is_primary', true)
                ->value('id'),
            'category_id' => Category::factory(),
            'stock_unit_id' => fn () => Unit::idFor('kg'),
            'title' => fake()->randomElement(['Roma tomatoes', 'Butternut', 'Sugar beans', 'Covo', 'Green mealies', 'Avocados']),
            'description' => fake()->sentence(12),
            'quality_grade' => QualityGrade::A,
            'currency' => 'USD',
            'offers_pickup' => true,
            'offers_delivery' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => ProductStatus::Active,
            'published_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(['status' => ProductStatus::PendingReview]);
    }

    public function withVariant(string $unit = 'kg', string $price = '1.20', string $baseQuantity = '1', array $attributes = []): static
    {
        return $this->afterCreating(fn (Product $product) => $product->variants()->create([
            'unit_id' => Unit::idFor($unit),
            'base_quantity' => $baseQuantity,
            'price_minor' => Money::toMinor($price),
            'min_order_quantity' => '1',
            ...$attributes,
        ]));
    }

    /**
     * Goes through the inventory service so batches, totals and the ledger stay consistent.
     */
    public function withStock(string $quantity, ?string $harvestedOn = null): static
    {
        return $this->afterCreating(fn (Product $product) => app(InventoryService::class)
            ->receive($product, null, $quantity, $harvestedOn));
    }

    /**
     * An image row without files, for tests that only need a listing to have a photo.
     */
    public function withImage(): static
    {
        return $this->afterCreating(function (Product $product) {
            $name = 'products/'.$product->id.'/'.Str::uuid7();

            $product->images()->create([
                'disk' => 'public',
                'path' => "{$name}.webp",
                'thumb_path' => "{$name}_thumb.webp",
                'width' => 1600,
                'height' => 1200,
                'size_bytes' => 150_000,
                'position' => $product->images()->count(),
            ]);
        });
    }

    /**
     * A live listing buyers can find: active, photographed, priced and in stock.
     */
    public function listed(string $stock = '100'): static
    {
        return $this->active()->withVariant()->withImage()->withStock($stock);
    }
}
