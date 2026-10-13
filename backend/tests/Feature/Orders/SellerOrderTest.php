<?php

namespace Tests\Feature\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Models\Farm;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class SellerOrderTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Order, 3: ProductVariant} farmer, buyer, order, variant
     */
    private function placed(string $quantity = '10', ?Farm $farm = null, ?User $farmer = null, string $unit = 'kg', string $price = '1.20'): array
    {
        if ($farm === null) {
            [$farmer, $farm] = $this->farmerWithFarm();
        }

        $variant = $this->listing($farm, '100', $unit, $price);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, $quantity);

        return [$farmer, $buyer, $this->checkout($buyer), $variant];
    }

    public function test_farm_accepts_and_the_buyer_is_asked_to_pay(): void
    {
        [$farmer, $buyer, $order] = $this->placed();
        $sellerOrder = $order->sellerOrders()->first();
        Sanctum::actingAs($farmer);

        // The buyer's number is only shared once the farm commits to the order.
        $this->getJson("/api/v1/seller-orders/{$sellerOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.buyer.name', $buyer->name)
            ->assertJsonPath('data.buyer.phone', null);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.buyer.phone', $buyer->phone);

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertTrue($order->payment_due_at->between(now()->addMinutes(179), now()->addMinutes(181)));
        $this->assertStringContainsString("Order {$order->number} is confirmed. Pay \$12.00", $this->smsTo($buyer->phone)[0]);
    }

    public function test_farm_can_supply_less_than_ordered(): void
    {
        [$farmer, , $order, $variant] = $this->placed();
        $sellerOrder = $order->sellerOrders()->first();
        $item = $sellerOrder->items()->first();
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/accept", ['items' => [['id' => $item->id, 'quantity' => '6']]])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', '10')
            ->assertJsonPath('data.items.0.confirmed_quantity', '6')
            ->assertJsonPath('data.total', '7.20');

        $this->assertSame('6.000', $variant->product->fresh()->stock_reserved);
        $this->assertSame(720, $order->fresh()->total_minor);
    }

    public function test_accepted_quantities_are_checked(): void
    {
        [$farmer, , $order] = $this->placed('5', unit: 'tray', price: '4.50');
        $sellerOrder = $order->sellerOrders()->first();
        $item = $sellerOrder->items()->first();
        $url = "/api/v1/seller-orders/{$sellerOrder->id}/accept";
        Sanctum::actingAs($farmer);

        $this->postJson($url, ['items' => [['id' => $item->id, 'quantity' => '6']]])->assertJsonValidationErrors('items.0.quantity');
        $this->postJson($url, ['items' => [['id' => $item->id, 'quantity' => '2.5']]])->assertJsonValidationErrors('items.0.quantity');
        $this->postJson($url, ['items' => [['id' => 'nope', 'quantity' => '1']]])->assertJsonValidationErrors('items.0.id');
        $this->postJson($url, ['items' => [['id' => $item->id, 'quantity' => '0']]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nothing would be supplied. Reject the order instead.');
    }

    public function test_rejecting_frees_the_stock_and_cancels_a_single_farm_order(): void
    {
        [$farmer, $buyer, $order, $variant] = $this->placed();
        $sellerOrder = $order->sellerOrders()->first();
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/reject")->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/reject", ['reason' => 'Hail damaged the crop'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Hail damaged the crop');

        $order->refresh();
        $this->assertSame('0.000', $variant->product->fresh()->stock_reserved);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancellationReason::NotConfirmed, $order->cancellation_reason);
        $this->assertStringContainsString('was cancelled because the farms could not supply it.', $this->smsTo($buyer->phone)[0]);
    }

    public function test_the_buyer_pays_only_for_what_was_accepted(): void
    {
        [$tomatoFarmer, $tomatoFarm] = $this->farmerWithFarm();
        [$eggFarmer, $eggFarm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($tomatoFarm), '10');
        $this->addToCart($buyer, $this->listing($eggFarm, '20', 'tray', '4.50'), '2');
        $order = $this->checkout($buyer);
        [$tomatoes, $eggs] = $order->sellerOrders()->get()->all();

        Sanctum::actingAs($eggFarmer);
        $this->postJson("/api/v1/seller-orders/{$eggs->id}/reject", ['reason' => 'Sold out'])->assertOk();
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->fresh()->status);

        Sanctum::actingAs($tomatoFarmer);
        $this->postJson("/api/v1/seller-orders/{$tomatoes->id}/accept")->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(1200, $order->total_minor);
        $this->assertStringContainsString('Some items were not available. Pay $12.00', $this->smsTo($buyer->phone)[0]);
    }

    public function test_only_the_farm_answers_and_only_once_in_time(): void
    {
        [$farmer, $buyer, $order] = $this->placed();
        $sellerOrder = $order->sellerOrders()->first();
        $url = "/api/v1/seller-orders/{$sellerOrder->id}/accept";

        Sanctum::actingAs($buyer);
        $this->postJson($url)->assertForbidden();

        [$outsider] = $this->farmerWithFarm();
        Sanctum::actingAs($outsider);
        $this->postJson($url)->assertNotFound();

        Sanctum::actingAs($farmer);
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertConflict()->assertJsonPath('message', 'This order has already been answered.');

        [$lateFarmer, , $late] = $this->placed();
        $this->travel(241)->minutes();
        Sanctum::actingAs($lateFarmer);
        $this->postJson('/api/v1/seller-orders/'.$late->sellerOrders()->first()->id.'/accept')
            ->assertConflict()
            ->assertJsonPath('message', 'The time to answer this order has passed.');
    }

    public function test_farms_list_their_incoming_orders(): void
    {
        [$farmer, , $order] = $this->placed();
        Sanctum::actingAs($farmer);
        $farmId = $order->sellerOrders()->first()->farm_id;

        $this->getJson("/api/v1/farms/{$farmId}/orders")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/farms/{$farmId}/orders?status=awaiting_confirmation")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/farms/{$farmId}/orders?status=accepted")->assertJsonCount(0, 'data');
    }
}
