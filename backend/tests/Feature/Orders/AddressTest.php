<?php

namespace Tests\Feature\Orders;

use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Home',
            'recipient_name' => 'Rudo Moyo',
            'phone' => '0771234567',
            'province' => 'Harare',
            'district' => 'Harare',
            'address_line' => '12 Samora Machel Avenue',
            'directions' => 'Blue gate opposite the clinic',
            'latitude' => -17.8292,
            'longitude' => 31.0522,
        ], $overrides);
    }

    public function test_the_first_address_is_the_default_and_the_default_can_move(): void
    {
        Sanctum::actingAs($this->buyer());

        $home = $this->postJson('/api/v1/me/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.phone', '+263771234567')
            ->json('data.id');

        $shop = $this->postJson('/api/v1/me/addresses', $this->payload(['label' => 'Shop', 'is_default' => true]))
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->getJson('/api/v1/me/addresses')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $shop)
            ->assertJsonPath('data.1.is_default', false);

        $this->patchJson("/api/v1/me/addresses/{$shop}", ['is_default' => false])->assertUnprocessable();

        // Removing the default hands the role to another address.
        $this->deleteJson("/api/v1/me/addresses/{$shop}")->assertNoContent();
        $this->getJson('/api/v1/me/addresses')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $home)
            ->assertJsonPath('data.0.is_default', true);
    }

    public function test_addresses_are_private(): void
    {
        $address = UserAddress::factory()->create();
        Sanctum::actingAs($this->buyer());

        $this->patchJson("/api/v1/me/addresses/{$address->id}", ['label' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/v1/me/addresses/{$address->id}")->assertNotFound();
    }

    public function test_address_fields_are_validated(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/me/addresses', $this->payload(['province' => 'Gauteng', 'latitude' => 200, 'phone' => '12']))
            ->assertJsonValidationErrors(['province', 'latitude', 'phone']);
    }
}
