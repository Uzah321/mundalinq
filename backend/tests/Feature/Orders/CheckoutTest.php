<?php

namespace Tests\Feature\Orders;

use App\Enums\RoleSlug;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_quote_prices_pickup_and_delivery(): void
    {
        [, $farm] = $this->deliveringFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $address = $this->harareAddress($buyer);
        $this->addToCart($buyer, $variant, '10');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/quote')
            ->assertOk()
            ->assertJsonPath('data.sellers.0.fulfilment_method', 'pickup')
            ->assertJsonPath('data.sellers.0.available_methods', ['pickup', 'delivery'])
            ->assertJsonPath('data.total', '12.00');

        $delivery = ['fulfilment' => [$farm->id => 'delivery'], 'address_id' => $address->id];

        $this->postJson('/api/v1/checkout/quote', $delivery)
            ->assertJsonPath('data.sellers.0.delivery_fee', '2.00')
            ->assertJsonPath('data.total', '14.00')
            ->assertJsonPath('data.can_place_order', true);

        // Free delivery from $50.
        $this->addToCart($buyer, $variant, '50');
        $this->postJson('/api/v1/checkout/quote', $delivery)
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.total', '60.00');
    }

    public function test_delivery_needs_an_address_in_the_farms_area(): void
    {
        [, $farm] = $this->deliveringFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        $bulawayo = UserAddress::factory()->for($buyer)->at(-20.15, 28.58)->create();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/quote', ['fulfilment' => [$farm->id => 'delivery']])
            ->assertJsonPath('data.problems.0.code', 'address_required')
            ->assertJsonPath('data.can_place_order', false);

        $this->postJson('/api/v1/checkout/quote', ['fulfilment' => [$farm->id => 'delivery'], 'address_id' => $bulawayo->id])
            ->assertJsonPath('data.problems.0.code', 'outside_delivery_area');

        $this->postJson('/api/v1/checkout/quote', ['address_id' => UserAddress::factory()->create()->id])
            ->assertJsonValidationErrors('address_id');
    }

    public function test_pickup_only_farms_cannot_deliver(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/quote', ['fulfilment' => [$farm->id => 'delivery'], 'address_id' => $this->harareAddress($buyer)->id])
            ->assertJsonPath('data.problems.0.code', 'fulfilment_unavailable');
    }

    public function test_placing_an_order_reserves_stock_and_tells_the_farm(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/v1/orders', ['expected_total' => '12.00', 'note' => 'Call when ready'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.total', '12.00')
            ->assertJsonPath('data.note', 'Call when ready')
            ->assertJsonPath('data.seller_orders.0.status', 'awaiting_confirmation')
            ->assertJsonPath('data.seller_orders.0.fulfilment_method', 'pickup')
            ->assertJsonPath('data.seller_orders.0.items.0.title', $variant->product->title)
            ->assertJsonPath('data.seller_orders.0.items.0.quantity', '10')
            ->assertJsonMissingPath('data.seller_orders.0.handover_code');

        $number = $response->json('data.number');
        $this->assertMatchesRegularExpression('/^ML-[A-HJ-NP-Z2-9]{6}$/', $number);
        $this->assertSame('10.000', $variant->product->fresh()->stock_reserved);
        $this->assertSame(0, $buyer->cart->items()->count());

        $sms = $this->smsTo($farmer->phone);
        $this->assertCount(1, $sms);
        $this->assertStringContainsString("New order {$number}-1 on MundaLinq: 1 item(s), \$12.00.", $sms[0]);
    }

    public function test_one_order_with_a_part_per_farm(): void
    {
        [, $tomatoFarm] = $this->farmerWithFarm();
        [, $eggFarm] = $this->farmerWithFarm();
        $tomatoes = $this->listing($tomatoFarm);
        $crate = $tomatoes->product->variants()->create([
            'unit_id' => Unit::idFor('crate'),
            'base_quantity' => '28',
            'price_minor' => 3000,
            'min_order_quantity' => '1',
        ]);
        $eggs = $this->listing($eggFarm, '20', 'tray', '4.50');
        $buyer = $this->buyer();
        $this->addToCart($buyer, $tomatoes, '10');
        $this->addToCart($buyer, $crate, '2');
        $this->addToCart($buyer, $eggs, '3');

        $order = $this->checkout($buyer);

        $this->assertSame([$order->number.'-1', $order->number.'-2'], $order->sellerOrders()->pluck('number')->all());
        $this->assertSame(1200 + 6000 + 1350, $order->total_minor);
        // The kg and crate options draw on the same stock: 10 kg + 2 × 28 kg.
        $this->assertSame('66.000', $tomatoes->product->fresh()->stock_reserved);
        $this->assertSame('3.000', $eggs->product->fresh()->stock_reserved);
    }

    public function test_changed_prices_must_be_confirmed_again(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        $variant->update(['price_minor' => 150]);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/orders', ['expected_total' => '12.00'])
            ->assertConflict()
            ->assertJsonPath('total', '15.00');

        $this->assertSame('0.000', $variant->product->fresh()->stock_reserved);
        $this->postJson('/api/v1/orders', ['expected_total' => '15.00'])->assertCreated();
    }

    public function test_orders_with_problems_are_refused_with_the_list(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm, '10');
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '8');
        $variant->product->forceFill(['stock_reserved' => '5'])->save();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/orders', ['expected_total' => '9.60'])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'insufficient_stock');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_items_keep_the_name_and_price_they_were_bought_at(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        $order = $this->checkout($buyer);
        $boughtAs = $variant->product->title;

        $variant->update(['price_minor' => 999]);
        $variant->product->update(['title' => 'Renamed later']);

        $item = $order->sellerOrders()->first()->items()->first();
        $this->assertSame(120, $item->unit_price_minor);
        $this->assertSame($boughtAs, $item->product_title);
    }

    public function test_buyers_need_a_verified_phone_and_cannot_order_the_same_cart_twice(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);

        $unverified = User::factory()->unverifiedPhone()->withRole(RoleSlug::Buyer)->create();
        $this->addToCart($unverified, $variant, '1');
        Sanctum::actingAs($unverified);
        $this->postJson('/api/v1/orders', ['expected_total' => '1.20'])->assertForbidden();

        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '1');
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/orders', ['expected_total' => '1.20'])->assertCreated();
        $this->postJson('/api/v1/orders', ['expected_total' => '1.20'])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'cart_empty');
    }
}
