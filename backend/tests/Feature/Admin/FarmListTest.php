<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmListTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_a_token(): void
    {
        $this->getJson('/api/v1/admin/farms')->assertUnauthorized();
    }

    public function test_returns_403_for_support_agents(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->getJson('/api/v1/admin/farms')->assertForbidden();
    }

    public function test_lists_only_farms_that_have_not_sent_documents_with_owner_contact(): void
    {
        $owner = User::factory()->withRole(RoleSlug::Farmer)->create(['name' => 'Tendai Moyo', 'phone' => '+263775550001']);
        $waiting = Farm::factory()->ownedBy($owner)->create(['name' => 'Moyo Farm']);
        Farm::factory()->pending()->create();
        Farm::factory()->approved()->create();
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Admin)->create());

        $this->getJson('/api/v1/admin/farms?verification_status=unverified')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $waiting->id)
            ->assertJsonPath('data.0.owners.0.name', 'Tendai Moyo')
            ->assertJsonPath('data.0.owners.0.phone', '+263775550001')
            ->assertJsonPath('data.0.listings_count', 0)
            ->assertJsonPath('data.0.primary_location.district', 'Goromonzi');
    }

    public function test_finds_farms_by_name_or_owner_phone(): void
    {
        $owner = User::factory()->withRole(RoleSlug::Farmer)->create(['phone' => '+263775550001']);
        $farm = Farm::factory()->ownedBy($owner)->approved()->create(['name' => 'MunyekiMbiti']);
        Farm::factory()->approved()->create(['name' => 'Moyo Farm']);
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Admin)->create());

        $this->getJson('/api/v1/admin/farms?q=munyeki')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $farm->id);
        $this->getJson('/api/v1/admin/farms?q=5550001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $farm->id);
    }

    public function test_returns_422_for_an_unknown_verification_status(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Admin)->create());

        $this->getJson('/api/v1/admin/farms?verification_status=archived')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['verification_status' => 'The selected verification status is invalid.']);
    }
}
