<?php

namespace Tests\Feature\Farms;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class FarmLocationPrivacyTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    public function test_the_public_sees_approximate_coordinates_and_members_see_exact_ones(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $farm->primaryLocation->update(['latitude' => -17.8216123, 'longitude' => 31.3722987, 'address_line' => 'Plot 7, Bromley Road']);

        $this->getJson("/api/v1/farms/{$farm->id}")
            ->assertOk()
            ->assertJsonPath('data.locations.0.latitude', -17.82)
            ->assertJsonPath('data.locations.0.longitude', 31.37)
            ->assertJsonPath('data.locations.0.address_line', null);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}")
            ->assertJsonPath('data.locations.0.latitude', -17.8216123)
            ->assertJsonPath('data.locations.0.address_line', 'Plot 7, Bromley Road');
    }

    public function test_a_location_with_listings_cannot_be_removed(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);
        $depot = $this->postJson("/api/v1/farms/{$farm->id}/locations", [
            'name' => 'Depot',
            'province' => 'Harare',
            'district' => 'Harare',
            'latitude' => -17.83,
            'longitude' => 31.05,
        ])->assertCreated()->json('data.id');
        $product = Product::factory()->withVariant()->create(['farm_id' => $farm->id, 'farm_location_id' => $depot]);

        $this->deleteJson("/api/v1/farms/{$farm->id}/locations/{$depot}")->assertConflict();

        // Once the listing is archived the location can go; the archived listing moves to the primary location.
        $product->delete();
        $this->deleteJson("/api/v1/farms/{$farm->id}/locations/{$depot}")->assertNoContent();
        $this->assertSame($farm->primaryLocation->id, Product::withTrashed()->find($product->id)->farm_location_id);
    }
}
