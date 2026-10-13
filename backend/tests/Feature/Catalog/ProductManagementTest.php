<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Farm;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    public function test_farmer_creates_a_draft_with_selling_options_and_first_stock(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $response = $this->postJson('/api/v1/products', $this->productPayload($farm))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.stock.on_hand', '500')
            ->assertJsonPath('data.stock.available', '500')
            ->assertJsonPath('data.last_harvested_on', Market::today())
            ->assertJsonPath('data.variants.0.unit.code', 'kg')
            ->assertJsonPath('data.variants.0.price', '1.20')
            ->assertJsonPath('data.variants.0.min_order_quantity', '5')
            ->assertJsonPath('data.variants.1.base_quantity', '28')
            ->assertJsonPath('data.variants.1.available_quantity', '17')
            ->assertJsonPath('data.variants.1.price_tiers.0.price', '27.00')
            ->assertJsonPath('data.farm_location_id', $farm->primaryLocation->id);

        $product = Product::findOrFail($response->json('data.id'));
        $this->assertSame(1, $product->batches()->count());
        $this->assertSame('receipt', InventoryMovement::sole()->type->value);
    }

    public function test_drafts_are_allowed_before_the_farm_is_verified(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm(approved: false);
        Sanctum::actingAs($farmer);

        $this->postJson('/api/v1/products', $this->productPayload($farm))->assertCreated();
    }

    public function test_only_farm_members_can_list_for_a_farm(): void
    {
        [, $approvedFarm] = $this->farmerWithFarm();
        [, $unverifiedFarm] = $this->farmerWithFarm(approved: false);
        [$outsider] = $this->farmerWithFarm();
        Sanctum::actingAs($outsider);

        $this->postJson('/api/v1/products', $this->productPayload($approvedFarm))->assertForbidden();
        $this->postJson('/api/v1/products', $this->productPayload($unverifiedFarm))->assertNotFound();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_malformed_ids_are_validation_errors_not_crashes(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: ['farm_id' => 'nope']))
            ->assertJsonValidationErrors('farm_id');

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: ['category_id' => 'nope', 'farm_location_id' => 'nope']))
            ->assertJsonValidationErrors(['category_id', 'farm_location_id']);
    }

    public function test_listing_rules_are_enforced(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);
        $vegetables = Category::factory()->create();
        Category::factory()->childOf($vegetables)->create();
        $otherFarmLocation = Farm::factory()->create()->primaryLocation;

        $this->postJson('/api/v1/products', $this->productPayload($farm, $vegetables))
            ->assertJsonValidationErrors(['category_id' => 'Choose a more specific category.']);

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: ['farm_location_id' => $otherFarmLocation->id]))
            ->assertJsonValidationErrors('farm_location_id');

        // The farm only offers pickup.
        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: ['offers_delivery' => true]))
            ->assertJsonValidationErrors('offers_delivery');

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: [
            'initial_stock' => ['quantity' => '10', 'harvested_on' => now(Market::timezone())->addDay()->toDateString()],
        ]))->assertJsonValidationErrors('initial_stock.harvested_on');
    }

    public function test_whole_number_units_and_duplicate_options_are_refused(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: [
            'stock_unit' => 'head',
            'variants' => [['unit' => 'head', 'price' => '0.80']],
            'initial_stock' => ['quantity' => '10.5'],
        ]))->assertJsonValidationErrors('initial_stock.quantity');

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: [
            'variants' => [['unit' => 'kg', 'price' => '1.20'], ['unit' => 'kg', 'price' => '1.10']],
        ]))->assertJsonValidationErrors('variants.1.unit');

        $this->postJson('/api/v1/products', $this->productPayload($farm, overrides: [
            'variants' => [['unit' => 'crate', 'price' => '30']],
        ]))->assertJsonValidationErrors(['variants.0.base_quantity' => 'Say how many kilograms are in one crate.']);
    }

    public function test_stock_unit_is_fixed_after_creation(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/v1/products/{$product->id}", ['stock_unit' => 'crate'])
            ->assertJsonValidationErrors('stock_unit');

        $this->assertSame(Unit::idFor('kg'), $product->fresh()->stock_unit_id);
    }

    public function test_renaming_a_live_listing_sends_it_back_to_review_but_other_edits_do_not(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/v1/products/{$product->id}", ['description' => 'Now even redder.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->patchJson("/api/v1/products/{$product->id}", ['title' => 'Something else entirely'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');
    }

    public function test_farm_settings_limit_a_listings_fulfilment(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/v1/products/{$product->id}", ['offers_delivery' => true])
            ->assertJsonValidationErrors('offers_delivery');

        $this->patchJson("/api/v1/products/{$product->id}", ['offers_pickup' => false])
            ->assertJsonValidationErrors('offers_pickup');
    }

    public function test_archived_listings_disappear(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/v1/farms/{$farm->id}/products")->assertJsonCount(0, 'data');
        $this->assertSoftDeleted($product);
    }

    public function test_farm_product_list_shows_every_status_to_members_only(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/farms/{$farm->id}/products")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/farms/{$farm->id}/products?status=draft")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', ProductStatus::Draft->value);

        [$outsider] = $this->farmerWithFarm();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/farms/{$farm->id}/products")->assertForbidden();
    }
}
