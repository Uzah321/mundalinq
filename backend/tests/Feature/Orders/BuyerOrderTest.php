<?php

namespace Tests\Feature\Orders;

use App\Enums\PaymentMethod;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class BuyerOrderTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_buyers_see_only_their_own_orders_and_farms_only_their_part(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '1');
        $order = $this->checkout($buyer);
        $other = $this->buyer();
        $this->addToCart($other, $variant, '2');
        $this->checkout($other);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.seller_orders.0.farm.name', $farm->name);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->getJson('/api/v1/seller-orders/'.$order->sellerOrders()->first()->id)
            ->assertOk()
            ->assertJsonMissingPath('data.handover_code');
    }

    public function test_buyer_can_cancel_before_paying_and_the_stock_returns(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        $order = $this->acceptAll($this->checkout($buyer));
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'buyer_cancelled')
            ->assertJsonPath('data.seller_orders.0.status', 'cancelled');

        $this->assertSame('0.000', $variant->product->fresh()->stock_reserved);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertConflict();
    }

    public function test_the_farm_is_told_when_the_buyer_cancels(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '2');
        $order = $this->checkout($buyer);
        $part = $order->sellerOrders()->sole();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $sms = collect($this->sms->messages)->where('phone', $farmer->phone)->last();
        $this->assertSame("The buyer cancelled order {$part->number}. You do not need to prepare it.", $sms['message']);
        $this->assertContains("Order {$part->number} cancelled", $farmer->notifications()->get()->pluck('data.title'));
    }

    public function test_cancelling_waits_for_an_open_payment_and_stops_once_paid(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();

        $this->addToCart($buyer, $variant, '10');
        $open = $this->acceptAll($this->checkout($buyer));
        app(PaymentService::class)->initiate($open, $buyer, PaymentMethod::MobileMoney, '+263779999999');

        $paid = $this->paidOrder($buyer, $variant, '5');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$open->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'A payment is in progress. Wait for it to finish before cancelling.');
        $this->postJson("/api/v1/orders/{$paid->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'This order can no longer be cancelled.');
    }
}
