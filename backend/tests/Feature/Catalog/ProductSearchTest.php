<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Farm;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    private function listed(array $attributes = [], string $stock = '100'): Product
    {
        return Product::factory()->listed($stock)->create($attributes);
    }

    private function placeAt(Product $product, float $latitude, float $longitude): void
    {
        $product->location->update(['latitude' => $latitude, 'longitude' => $longitude]);
    }

    public function test_only_live_in_stock_listings_from_verified_farms_are_shown(): void
    {
        $visible = $this->listed();
        Product::factory()->withVariant()->withImage()->withStock('10')->create(); // draft
        Product::factory()->pendingReview()->withVariant()->withImage()->withStock('10')->create();
        Product::factory()->active()->withVariant()->withImage()->create(); // no stock
        $this->listed(['expires_at' => now()->subDay()]);
        $this->listed(['farm_id' => Farm::factory()->pending()->create()->id]);
        $this->listed()->forceFill(['status' => 'paused'])->save();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.from_price.price', '1.20')
            ->assertJsonPath('data.0.from_price.unit', 'kg')
            ->assertJsonPath('data.0.available_quantity', '100')
            ->assertJsonPath('data.0.farm.verified', true)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_category_filter_includes_subcategories(): void
    {
        $vegetables = Category::factory()->create(['slug' => 'vegetables']);
        $tomatoes = Category::factory()->childOf($vegetables)->create(['slug' => 'tomatoes']);
        $product = $this->listed(['category_id' => $tomatoes->id]);
        $this->listed();

        $this->getJson('/api/v1/products?category=vegetables')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/v1/products?category=tomatoes')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?category=no-such-thing')->assertJsonValidationErrors('category');
    }

    public function test_text_search_matches_titles_and_category_names(): void
    {
        $beans = Category::factory()->create(['name' => 'Sugar beans']);
        $this->listed(['title' => 'Roma tomatoes']);
        $this->listed(['title' => 'Red speckled', 'category_id' => $beans->id]);

        $this->getJson('/api/v1/products?q=TOMATO')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Roma tomatoes');
        $this->getJson('/api/v1/products?q=beans')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Red speckled');
        $this->getJson('/api/v1/products?q=100%25')->assertJsonCount(0, 'data');
    }

    public function test_price_grade_and_fulfilment_filters(): void
    {
        $cheap = $this->listed(['quality_grade' => 'B']);
        $delivered = Product::factory()->listed()->withVariant('crate', '30.00', '28')->create([
            'farm_id' => Farm::factory()->approved()->create(['offers_delivery' => true, 'delivery_radius_km' => 20])->id,
            'offers_delivery' => true,
        ]);

        $this->getJson('/api/v1/products?min_price=10')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $delivered->id);
        $this->getJson('/api/v1/products?grade=B')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheap->id);
        $this->getJson('/api/v1/products?fulfilment=delivery')->assertJsonCount(1, 'data')->assertJsonPath('data.0.offers_delivery', true);
        $this->getJson('/api/v1/products?min_price=10&max_price=5')->assertJsonValidationErrors('max_price');
    }

    public function test_radius_search_sorts_by_distance_in_whole_kilometres(): void
    {
        $harare = $this->listed(['title' => 'Harare']);
        $chitungwiza = $this->listed(['title' => 'Chitungwiza']);
        $bulawayo = $this->listed(['title' => 'Bulawayo']);
        $this->placeAt($harare, -17.8292, 31.0522);
        $this->placeAt($chitungwiza, -18.0127, 31.0756);
        $this->placeAt($bulawayo, -20.1325, 28.6265);

        $response = $this->getJson('/api/v1/products?lat=-17.8292&lng=31.0522&radius_km=50')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Harare')
            ->assertJsonPath('data.0.distance_km', 1)
            ->assertJsonPath('data.1.title', 'Chitungwiza');

        $this->assertEqualsWithDelta(20, $response->json('data.1.distance_km'), 2);
        $this->getJson('/api/v1/products?lat=-17.8292&lng=31.0522&radius_km=500')->assertJsonCount(3, 'data');
    }

    public function test_sorting_by_price_and_distance_validation(): void
    {
        $this->listed(['title' => 'Dear'])->variants()->update(['price_minor' => 900]);
        $this->listed(['title' => 'Cheap'])->variants()->update(['price_minor' => 100]);

        $this->getJson('/api/v1/products?sort=price_asc')->assertJsonPath('data.0.title', 'Cheap');
        $this->getJson('/api/v1/products?sort=price_desc')->assertJsonPath('data.0.title', 'Dear');
        $this->getJson('/api/v1/products?sort=nearest')->assertJsonValidationErrors('sort');
    }

    public function test_listing_detail_shows_buyers_only_what_they_can_order(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed('100')
            ->withVariant('crate', '30.00', '28', ['is_active' => false])
            ->create(['farm_id' => $farm->id]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.available_quantity', '100')
            ->assertJsonPath('data.location.province', 'Mashonaland East')
            ->assertJsonMissingPath('data.stock')
            ->assertJsonMissingPath('data.location.latitude');

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.stock.on_hand', '100');
    }

    public function test_drafts_are_private_to_their_farm(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $draft = Product::factory()->withVariant()->create([
            'farm_id' => $farm->id,
            'stock_unit_id' => Unit::idFor('kg'),
        ]);

        $this->getJson("/api/v1/products/{$draft->id}")->assertNotFound();

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/products/{$draft->id}")->assertOk()->assertJsonPath('data.status', 'draft');
    }
}
