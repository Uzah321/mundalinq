<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleSlug;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\SellerOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_kpis_cover_trade_fulfilment_revenue_and_work_queues(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);

        $buyer = $this->buyer();
        $sellerOrder = $this->paidOrder($buyer, $variant)->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);
        app(OrderService::class)->confirmReceipt($sellerOrder, $buyer);

        $this->addToCart($canceller = $this->buyer(), $variant, '2');
        app(OrderService::class)->cancelByBuyer($this->checkout($canceller), $canceller);

        Product::factory()->pendingReview()->withVariant()->create();

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.gmv', '12.00')
            ->assertJsonPath('data.orders.placed', 2)
            ->assertJsonPath('data.orders.paid', 1)
            ->assertJsonPath('data.orders.completed', 1)
            ->assertJsonPath('data.orders.cancelled', 1)
            ->assertJsonPath('data.orders.fulfilment_rate', '50.0')
            ->assertJsonPath('data.orders.cancellation_rate', '50.0')
            ->assertJsonPath('data.revenue.platform_fees', '0.60')
            ->assertJsonPath('data.buyers.active', 1)
            ->assertJsonPath('data.buyers.repeat_rate', '0.0')
            ->assertJsonPath('data.sellers.active_verified', 1)
            ->assertJsonPath('data.queues.listings_to_review', 1)
            ->assertJsonPath('data.queues.farms_awaiting_payout', 1)
            ->assertJsonPath('data.queues.open_disputes', 0);
    }

    public function test_support_agents_do_not_see_the_business_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }
}
