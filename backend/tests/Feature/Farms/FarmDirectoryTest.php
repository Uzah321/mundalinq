<?php

namespace Tests\Feature\Farms;

use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_lists_only_approved_farms(): void
    {
        $approved = Farm::factory()->approved()->create(['name' => 'Approved Farm']);
        Farm::factory()->create(['name' => 'New Farm']);
        Farm::factory()->pending()->create(['name' => 'Pending Farm']);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.primary_location.province', 'Mashonaland East')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_directory_can_be_filtered(): void
    {
        Farm::factory()->approved()->create(['name' => 'Goromonzi Greens']);
        $bulawayo = Farm::factory()->approved()->create(['name' => 'City Poultry']);
        $bulawayo->locations()->update(['province' => 'Bulawayo', 'district' => 'Bulawayo']);

        $this->getJson('/api/v1/farms?province=Bulawayo')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'City Poultry');

        $this->getJson('/api/v1/farms?q=greens')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Goromonzi Greens');

        $this->getJson('/api/v1/farms?q=100%')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unapproved_farm_is_hidden_from_the_public_but_visible_to_members_and_staff(): void
    {
        $owner = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm = Farm::factory()->ownedBy($owner)->create();

        $this->getJson("/api/v1/farms/{$farm->id}")->assertNotFound();

        $token = $owner->createToken('Phone')->plainTextToken;
        $this->getJson("/api/v1/farms/{$farm->id}", ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Admin)->create());
        $this->getJson("/api/v1/farms/{$farm->id}")->assertOk();
    }

    public function test_approved_farm_is_public(): void
    {
        $farm = Farm::factory()->approved()->create();

        $this->getJson("/api/v1/farms/{$farm->id}")
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonCount(1, 'data.locations');
    }

    public function test_malformed_farm_id_returns_not_found(): void
    {
        $this->getJson('/api/v1/farms/not-a-uuid')->assertNotFound();
    }
}
