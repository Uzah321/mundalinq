<?php

namespace Tests\Feature\Catalog;

use App\Models\Farm;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavouriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_saves_and_removes_favourites(): void
    {
        $tomatoes = Product::factory()->listed()->create();
        $onions = Product::factory()->listed()->create();
        Sanctum::actingAs($buyer = User::factory()->create());

        $this->putJson("/api/v1/me/favourites/{$tomatoes->id}")->assertNoContent();
        $this->putJson("/api/v1/me/favourites/{$tomatoes->id}")->assertNoContent();
        $this->travel(1)->second();
        $this->putJson("/api/v1/me/favourites/{$onions->id}")->assertNoContent();
        $this->assertSame(2, $buyer->favourites()->count());

        $this->getJson('/api/v1/me/favourites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $onions->id)
            ->assertJsonPath('data.1.id', $tomatoes->id)
            ->assertJsonPath('data.0.is_favourite', true);

        $this->deleteJson("/api/v1/me/favourites/{$onions->id}")->assertNoContent();
        $this->getJson('/api/v1/me/favourites')->assertJsonCount(1, 'data');
    }

    public function test_search_results_mark_favourites_for_signed_in_buyers_only(): void
    {
        $saved = Product::factory()->listed()->create();
        $buyer = User::factory()->create();
        $buyer->favourites()->create(['product_id' => $saved->id]);

        $this->getJson('/api/v1/products')->assertJsonMissingPath('data.0.is_favourite');

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.is_favourite', true);
    }

    public function test_paused_listings_stay_saved_but_are_hidden_until_live_again(): void
    {
        $product = Product::factory()->listed()->create();
        Sanctum::actingAs($buyer = User::factory()->create());
        $this->putJson("/api/v1/me/favourites/{$product->id}")->assertNoContent();

        $product->forceFill(['status' => 'paused'])->save();
        $this->getJson('/api/v1/me/favourites')->assertJsonCount(0, 'data');

        $product->forceFill(['status' => 'active'])->save();
        $this->getJson('/api/v1/me/favourites')->assertJsonCount(1, 'data');
    }

    public function test_listings_buyers_cannot_see_cannot_be_saved(): void
    {
        $unverifiedFarm = Product::factory()->listed()->create(['farm_id' => Farm::factory()->pending()->create()->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/me/favourites/{$unverifiedFarm->id}")->assertNotFound();
    }

    public function test_search_matches_the_farm_name(): void
    {
        $farm = Farm::factory()->approved()->create(['name' => 'Chipo Sunrise Gardens']);
        $listing = Product::factory()->listed()->create(['farm_id' => $farm->id, 'title' => 'Covo']);
        Product::factory()->listed()->create(['title' => 'Butternut']);

        $this->getJson('/api/v1/products?q=sunrise')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $listing->id);
    }
}
