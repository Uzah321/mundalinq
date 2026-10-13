<?php

namespace Tests\Feature\Orders;

use App\Enums\FarmMemberRole;
use App\Enums\OrderStatus;
use App\Enums\RoleSlug;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\SellerOrderService;
use App\Support\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class FulfilmentTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_pickup_handover_needs_the_buyers_code_and_takes_the_oldest_stock_first(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->active()->withImage()->withVariant()
            ->withStock('30', now(Market::timezone())->subDays(5)->toDateString())
            ->withStock('100', Market::today())
            ->create(['farm_id' => $farm->id]);
        $buyer = $this->buyer();
        $order = $this->paidOrder($buyer, $product->variants()->first(), '40');
        $sellerOrder = $order->sellerOrders()->first();

        Sanctum::actingAs($buyer);
        $code = $this->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.seller_orders.0.status', 'preparing')
            ->json('data.seller_orders.0.handover_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        Sanctum::actingAs($farmer);
        $handover = "/api/v1/seller-orders/{$sellerOrder->id}/handover";
        $this->postJson($handover, ['code' => $code])->assertConflict();

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/ready")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonMissingPath('data.handover_code');
        $this->assertStringContainsString("Show code {$code} when you collect it.", last($this->smsTo($buyer->phone)));

        $this->postJson($handover, ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        $this->postJson($handover, ['code' => $code])->assertOk()->assertJsonPath('data.status', 'delivered');

        $product->refresh();
        $this->assertSame('90.000', $product->stock_on_hand);
        $this->assertSame('0.000', $product->stock_reserved);
        $this->assertSame(['0.000', '90.000'], $product->batches()->orderBy('harvested_on')->pluck('quantity_on_hand')->all());
        $this->assertSame(['-30.000', '-10.000'], $product->movements()->where('type', 'sale')->orderBy('quantity')->pluck('quantity')->all());
    }

    public function test_delivery_orders_are_dispatched_then_handed_over(): void
    {
        [$farmer, $farm] = $this->deliveringFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $order = $this->paidOrder($buyer, $variant, '10', [$farm->id => 'delivery'], $this->harareAddress($buyer));
        $sellerOrder = $order->sellerOrders()->first();
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/seller-orders/{$sellerOrder->id}")
            ->assertJsonPath('data.delivery_address.label', 'Home')
            ->assertJsonPath('data.total', '14.00');

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispatch")->assertConflict();
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/ready")->assertOk();
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched');

        // Stock leaves with the delivery, not at handover.
        $this->assertSame('90.000', $variant->product->fresh()->stock_on_hand);
        $code = $sellerOrder->fresh()->handover_code;
        $this->assertStringContainsString("Give code {$code} to the person delivering it.", last($this->smsTo($buyer->phone)));

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/handover", ['code' => $code])->assertJsonPath('data.status', 'delivered');
    }

    public function test_pickup_orders_are_not_dispatched(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $order = $this->paidOrder($this->buyer(), $this->listing($farm));
        $sellerOrder = $order->sellerOrders()->first();
        app(SellerOrderService::class)->markReady($sellerOrder, $farmer);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispatch")->assertUnprocessable();
    }

    public function test_confirming_receipt_completes_the_sale_and_releases_the_earnings(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $order = $this->paidOrder($buyer, $this->listing($farm));
        $sellerOrder = $order->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")
            ->assertJsonPath('data.pending', '11.40')
            ->assertJsonPath('data.available', '0.00');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/confirm-receipt")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")
            ->assertJsonPath('data.pending', '0.00')
            ->assertJsonPath('data.available', '11.40')
            ->assertJsonCount(2, 'data.entries');
    }

    public function test_only_the_buyer_confirms_receipt_and_only_owners_see_earnings(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $order = $this->paidOrder($this->buyer(), $this->listing($farm));
        $manager = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($manager->id, ['role' => FarmMemberRole::Manager->value]);

        Sanctum::actingAs($farmer);
        $this->postJson('/api/v1/seller-orders/'.$order->sellerOrders()->first()->id.'/confirm-receipt')->assertForbidden();

        Sanctum::actingAs($manager);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")->assertForbidden();
    }
}
