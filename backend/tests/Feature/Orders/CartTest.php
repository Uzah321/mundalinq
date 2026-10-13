<?php

namespace Tests\Feature\Orders;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class CartTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_buyer_adds_updates_and_removes_items(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        Sanctum::actingAs($this->buyer());

        $itemId = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => '5'])
            ->assertOk()
            ->assertJsonPath('data.sellers.0.farm.id', $farm->id)
            ->assertJsonPath('data.sellers.0.items.0.quantity', '5')
            ->assertJsonPath('data.sellers.0.items.0.unit_price', '1.20')
            ->assertJsonPath('data.sellers.0.items.0.line_total', '6.00')
            ->assertJsonPath('data.total', '6.00')
            ->assertJsonPath('data.can_place_order', true)
            ->json('data.sellers.0.items.0.cart_item_id');

        // Adding the same option again sets its quantity rather than adding a line.
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => '7.5'])
            ->assertJsonCount(1, 'data.sellers.0.items')
            ->assertJsonPath('data.sellers.0.items.0.line_total', '9.00');

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => '2'])->assertJsonPath('data.total', '2.40');

        $this->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.sellers')
            ->assertJsonPath('data.problems.0.code', 'cart_empty');
    }

    public function test_cart_rules(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $eggs = $this->listing($farm, '10', 'tray', '4.50');
        $tomatoes = $this->listing($farm);
        $tomatoes->update(['min_order_quantity' => '5']);
        $draft = Product::factory()->withVariant()->withStock('10')->create(['farm_id' => $farm->id])->variants()->first();
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/cart/items', ['variant_id' => $draft->id, 'quantity' => '1'])
            ->assertJsonValidationErrors(['variant_id' => 'This item is no longer available.']);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $eggs->id, 'quantity' => '1.5'])
            ->assertJsonValidationErrors(['quantity' => 'Order whole trays.']);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $eggs->id, 'quantity' => '11'])
            ->assertJsonValidationErrors(['quantity' => 'Only 10 trays available.']);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $tomatoes->id, 'quantity' => '2'])
            ->assertJsonValidationErrors(['quantity' => 'The minimum order is 5 kilograms.']);
        $this->postJson('/api/v1/cart/items', ['variant_id' => 'nope', 'quantity' => '1'])
            ->assertJsonValidationErrors('variant_id');

        Sanctum::actingAs($farmer);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $tomatoes->id, 'quantity' => '5'])
            ->assertJsonValidationErrors(['variant_id' => 'You cannot buy from your own farm.']);
    }

    public function test_the_cart_flags_items_that_changed_since_they_were_added(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm, '10');
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '8');
        Sanctum::actingAs($buyer);

        // Other buyers have claimed most of the stock.
        $variant->product->forceFill(['stock_reserved' => '5'])->save();
        $this->getJson('/api/v1/cart')
            ->assertJsonPath('data.sellers.0.items.0.problem.code', 'insufficient_stock')
            ->assertJsonPath('data.sellers.0.items.0.problem.message', 'Only 5 kilograms left.')
            ->assertJsonPath('data.can_place_order', false);

        $variant->product->forceFill(['status' => 'paused'])->save();
        $this->getJson('/api/v1/cart')->assertJsonPath('data.problems.0.code', 'unavailable');
    }

    public function test_cart_items_are_private_and_the_cart_has_a_limit(): void
    {
        config(['mundalinq.orders.max_cart_items' => 1]);
        [, $farm] = $this->farmerWithFarm();
        $first = $this->listing($farm);
        $second = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $first, '1');
        $item = $buyer->cart->items()->first();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $second->id, 'quantity' => '1'])->assertJsonValidationErrors('variant_id');

        Sanctum::actingAs($this->buyer());
        $this->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => '2'])->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$item->id}")->assertNotFound();
    }
}
