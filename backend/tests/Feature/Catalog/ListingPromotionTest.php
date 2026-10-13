<?php

namespace Tests\Feature\Catalog;

use App\Enums\FarmMemberRole;
use App\Enums\LedgerEntryType;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class ListingPromotionTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    /**
     * Earnings the farm can be paid out now, as a settled sale would leave them.
     */
    private function earn(Farm $farm, int $minor): void
    {
        LedgerEntry::forceCreate([
            'farm_id' => $farm->id,
            'type' => LedgerEntryType::Sale,
            'amount_minor' => $minor,
            'currency' => 'USD',
            'description' => 'Sale',
            'available_at' => now(),
        ]);
    }

    public function test_a_farm_features_a_live_listing_paid_from_its_earnings(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->earn($farm, 1000);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 2])->assertOk();

        $this->assertTrue($product->fresh()->featured_until->between(now()->addDays(13), now()->addDays(15)));
        $this->assertSame(800, app(SettlementService::class)->balances($farm)['available_minor']);
        $this->assertSame(-200, LedgerEntry::where('type', LedgerEntryType::Promotion)->sole()->amount_minor);
        $this->assertSame(200, ProductPromotion::sole()->amount_minor);
    }

    public function test_returns_422_when_the_farms_earnings_do_not_cover_it(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->earn($farm, 50);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Featuring for 1 week costs $1.00, paid from earnings that are ready to pay out. You have $0.50.');

        $this->assertNull($product->fresh()->featured_until);
    }

    public function test_returns_422_for_a_listing_that_is_not_on_sale(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $draft = Product::factory()->withVariant()->withImage()->create(['farm_id' => $farm->id]);
        $this->earn($farm, 1000);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$draft->id}/feature", ['weeks' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only listings that are on sale can be featured.');
    }

    public function test_returns_422_for_a_length_that_is_not_offered(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 3])->assertJsonValidationErrors('weeks');
    }

    public function test_returns_403_for_another_farms_listing(): void
    {
        [, $farm] = $this->farmerWithFarm();
        [$otherFarmer] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($otherFarmer);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 1])->assertForbidden();
    }

    public function test_returns_403_when_a_manager_features_a_listing(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $manager = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($manager->id, ['role' => FarmMemberRole::Manager->value]);
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->earn($farm, 1000);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the farm owner can do this.');
    }

    public function test_featuring_again_adds_the_weeks_on_to_the_end(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->earn($farm, 1000);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 1])->assertOk();
        $this->postJson("/api/v1/products/{$product->id}/feature", ['weeks' => 1])->assertOk();

        $this->assertTrue($product->fresh()->featured_until->between(now()->addDays(13), now()->addDays(15)));
    }

    public function test_featured_listings_come_first_with_a_badge_unless_the_buyer_sorts(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $featured = Product::factory()->listed()->create(['farm_id' => $farm->id, 'published_at' => now()->subDays(5), 'featured_until' => now()->addWeek()]);
        $newer = Product::factory()->listed()->create(['farm_id' => $farm->id, 'published_at' => now()]);
        Product::factory()->listed()->create(['farm_id' => $farm->id, 'published_at' => now()->subDay(), 'featured_until' => now()->subDay()]);

        $this->getJson('/api/v1/products')
            ->assertJsonPath('data.0.id', $featured->id)
            ->assertJsonPath('data.0.featured', true)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.1.featured', false);
        $this->getJson('/api/v1/products?featured=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $featured->id);
    }

    public function test_staff_feature_a_listing_for_free_and_can_stop_it(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->id}/feature", ['weeks' => 4])->assertOk()->assertJsonPath('data.featured', true);
        $this->assertSame(0, ProductPromotion::sole()->amount_minor);
        $this->assertFalse(LedgerEntry::where('type', LedgerEntryType::Promotion)->exists());

        $this->deleteJson("/api/v1/admin/products/{$product->id}/feature")->assertOk()->assertJsonPath('data.featured', false);
        $this->assertNull($product->fresh()->featured_until);
    }

    public function test_returns_403_when_a_farmer_uses_the_free_staff_route(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/admin/products/{$product->id}/feature", ['weeks' => 1])->assertForbidden();
    }
}
