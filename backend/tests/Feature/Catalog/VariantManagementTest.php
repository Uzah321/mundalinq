<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class VariantManagementTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    public function test_farmer_adds_updates_and_removes_selling_options(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withStock('100')->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $crate = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'unit' => 'crate',
            'base_quantity' => '25',
            'price' => '28.00',
            'price_tiers' => [['min_quantity' => '5', 'price' => '26.00']],
        ])->assertCreated()
            ->assertJsonPath('data.available_quantity', '4')
            ->assertJsonPath('data.price_tiers.0.min_quantity', '5')
            ->json('data.id');

        $this->patchJson("/api/v1/products/{$product->id}/variants/{$crate}", ['price' => '29.00', 'price_tiers' => []])
            ->assertOk()
            ->assertJsonPath('data.price', '29.00')
            ->assertJsonPath('data.base_quantity', '25')
            ->assertJsonCount(0, 'data.price_tiers');

        $this->deleteJson("/api/v1/products/{$product->id}/variants/{$crate}")->assertNoContent();
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_duplicate_selling_options_are_refused(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant('kg')->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/variants", ['unit' => 'kg', 'price' => '1.00'])
            ->assertJsonValidationErrors('unit');
    }

    public function test_a_published_listing_keeps_at_least_one_active_option(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $only = $product->variants()->sole();
        Sanctum::actingAs($farmer);

        $this->deleteJson("/api/v1/products/{$product->id}/variants/{$only->id}")->assertConflict();
        $this->patchJson("/api/v1/products/{$product->id}/variants/{$only->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($only->fresh()->is_active);
    }

    public function test_options_of_another_listing_cannot_be_reached_through_this_one(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        $foreign = Product::factory()->withVariant()->create()->variants()->sole();
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/v1/products/{$product->id}/variants/{$foreign->id}", ['price' => '0.01'])->assertNotFound();
    }
}
