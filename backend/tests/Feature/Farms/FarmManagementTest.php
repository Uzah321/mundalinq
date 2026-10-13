<?php

namespace Tests\Feature\Farms;

use App\Enums\FarmMemberRole;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmManagementTest extends TestCase
{
    use RefreshDatabase;

    private function farmPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Chipo Fresh Produce',
            'description' => 'Tomatoes, leafy greens and butternut.',
            'farmer_type' => 'smallholder',
            'contact_phone' => '0771234567',
            'offers_pickup' => true,
            'offers_delivery' => true,
            'delivery_radius_km' => 25,
            'location' => [
                'province' => 'Mashonaland East',
                'district' => 'Goromonzi',
                'ward' => '12',
                'latitude' => -17.8216,
                'longitude' => 31.3722,
            ],
        ], $overrides);
    }

    private function farmer(): User
    {
        return User::factory()->withRole(RoleSlug::Farmer)->create();
    }

    public function test_verified_farmer_can_create_a_farm_and_becomes_its_owner(): void
    {
        Sanctum::actingAs($farmer = $this->farmer());

        $response = $this->postJson('/api/v1/farms', $this->farmPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chipo Fresh Produce')
            ->assertJsonPath('data.contact_phone', '+263771234567')
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.locations.0.name', 'Main farm')
            ->assertJsonPath('data.locations.0.is_primary', true);

        $farm = Farm::findOrFail($response->json('data.id'));
        $this->assertSame(FarmMemberRole::Owner, $farm->memberRole($farmer));

        $this->getJson('/api/v1/me/farms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $farm->id)
            ->assertJsonPath('data.0.my_role', 'owner');
    }

    public function test_buyer_must_add_the_farmer_role_before_creating_a_farm(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Buyer)->create());

        $this->postJson('/api/v1/farms', $this->farmPayload())->assertForbidden();

        $this->postJson('/api/v1/me/roles', ['role' => 'farmer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['buyer', 'farmer']);

        $this->postJson('/api/v1/farms', $this->farmPayload())->assertCreated();
    }

    public function test_staff_roles_cannot_be_self_assigned(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Buyer)->create());

        $this->postJson('/api/v1/me/roles', ['role' => 'admin'])->assertUnprocessable();
    }

    public function test_phone_must_be_verified_to_create_a_farm(): void
    {
        Sanctum::actingAs(User::factory()->unverifiedPhone()->withRole(RoleSlug::Farmer)->create());

        $this->postJson('/api/v1/farms', $this->farmPayload())->assertForbidden();
    }

    public function test_farm_input_is_validated(): void
    {
        Sanctum::actingAs($this->farmer());

        $this->postJson('/api/v1/farms', $this->farmPayload(['location' => ['province' => 'Gauteng']]))
            ->assertJsonValidationErrors('location.province');

        $this->postJson('/api/v1/farms', $this->farmPayload(['offers_pickup' => false, 'offers_delivery' => false]))
            ->assertJsonValidationErrors('offers_pickup');

        $this->postJson('/api/v1/farms', $this->farmPayload(['delivery_radius_km' => null]))
            ->assertJsonValidationErrors('delivery_radius_km');

        $this->postJson('/api/v1/farms', $this->farmPayload(['location' => ['latitude' => 120]]))
            ->assertJsonValidationErrors('location.latitude');
    }

    public function test_owner_and_manager_can_update_but_outsiders_cannot(): void
    {
        $owner = $this->farmer();
        $manager = $this->farmer();
        $farm = Farm::factory()->ownedBy($owner)->ownedBy($manager, FarmMemberRole::Manager)->create();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/farms/{$farm->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');

        Sanctum::actingAs($manager);
        $this->patchJson("/api/v1/farms/{$farm->id}", ['description' => 'Updated'])->assertOk();

        // Unapproved farms are invisible to outsiders; approved ones are visible but not editable.
        Sanctum::actingAs($this->farmer());
        $this->patchJson("/api/v1/farms/{$farm->id}", ['name' => 'Hijacked'])->assertNotFound();

        $farm->forceFill(['verification_status' => 'approved'])->save();
        $this->patchJson("/api/v1/farms/{$farm->id}", ['name' => 'Hijacked'])->assertForbidden();

        $this->assertSame('Renamed', $farm->fresh()->name);
    }

    public function test_update_keeps_fulfilment_options_consistent(): void
    {
        $owner = $this->farmer();
        $farm = Farm::factory()->ownedBy($owner)->create(['offers_pickup' => true, 'offers_delivery' => false]);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/farms/{$farm->id}", ['offers_pickup' => false])
            ->assertJsonValidationErrors('offers_pickup');

        $this->patchJson("/api/v1/farms/{$farm->id}", ['offers_delivery' => true])
            ->assertJsonValidationErrors('delivery_radius_km');

        $this->patchJson("/api/v1/farms/{$farm->id}", ['offers_delivery' => true, 'delivery_radius_km' => 15])
            ->assertOk()
            ->assertJsonPath('data.delivery_radius_km', 15);
    }

    public function test_farmer_type_is_locked_once_verification_is_submitted(): void
    {
        $owner = $this->farmer();
        $farm = Farm::factory()->pending()->ownedBy($owner)->create();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/farms/{$farm->id}", ['farmer_type' => 'commercial'])
            ->assertJsonValidationErrors('farmer_type');
    }

    public function test_owner_can_manage_locations(): void
    {
        $owner = $this->farmer();
        $farm = Farm::factory()->ownedBy($owner)->create();
        $original = $farm->primaryLocation;
        Sanctum::actingAs($owner);

        $second = $this->postJson("/api/v1/farms/{$farm->id}/locations", [
            'name' => 'Bulawayo depot',
            'province' => 'Bulawayo',
            'district' => 'Bulawayo',
            'latitude' => -20.15,
            'longitude' => 28.58,
            'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');

        $this->assertFalse($original->fresh()->is_primary);

        $this->deleteJson("/api/v1/farms/{$farm->id}/locations/{$second}")->assertConflict();
        $this->deleteJson("/api/v1/farms/{$farm->id}/locations/{$original->id}")->assertNoContent();
        $this->assertSame(1, $farm->locations()->count());
    }

    public function test_location_from_another_farm_cannot_be_reached_through_this_farm(): void
    {
        $owner = $this->farmer();
        $farm = Farm::factory()->ownedBy($owner)->create();
        $otherLocation = Farm::factory()->create()->primaryLocation;
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/farms/{$farm->id}/locations/{$otherLocation->id}", ['name' => 'Mine now'])
            ->assertNotFound();
    }
}
