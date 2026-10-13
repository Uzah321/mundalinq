<?php

namespace Tests\Feature\Farms;

use App\Enums\FarmMemberRole;
use App\Enums\RoleSlug;
use App\Models\User;
use App\Services\Orders\SellerOrderService;
use App\Support\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class FarmAnalyticsTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_owner_sees_sales_answering_best_sellers_and_unsold_stock(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $tomatoes = $this->listing($farm, '100', 'kg', '1.20', ['title' => 'Roma tomatoes']);
        $covo = $this->listing($farm, '50', 'bunch', '0.50', ['title' => 'Covo']);
        $eggs = $this->listing($farm, '20', 'tray', '4.50', ['title' => 'Eggs']);

        $this->paidOrder($this->buyer(), $tomatoes, '10');
        $this->paidOrder($this->buyer(), $covo, '4');
        $this->addToCart($buyer = $this->buyer(), $covo, '2');
        app(SellerOrderService::class)->reject($this->checkout($buyer)->sellerOrders()->first(), $farmer, 'Out of covo today');

        Sanctum::actingAs($farmer);
        $report = $this->getJson("/api/v1/farms/{$farm->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.sales.orders', 2)
            ->assertJsonPath('data.sales.gross', '14.00')
            ->assertJsonPath('data.sales.platform_fees', '0.70')
            ->assertJsonPath('data.sales.net', '13.30')
            ->assertJsonPath('data.orders.received', 3)
            ->assertJsonPath('data.orders.accepted', 2)
            ->assertJsonPath('data.orders.rejected', 1)
            ->assertJsonPath('data.orders.acceptance_rate', '66.7')
            ->assertJsonPath('data.top_products.0.title', 'Roma tomatoes')
            ->assertJsonPath('data.top_products.0.quantity', '10')
            ->assertJsonPath('data.top_products.0.unit', 'kilograms')
            ->assertJsonPath('data.top_products.0.revenue', '12.00')
            ->assertJsonPath('data.stock.live_listings', 3)
            ->assertJsonPath('data.stock.unsold_count', 1)
            ->assertJsonPath('data.stock.unsold.0.product_id', $eggs->product_id)
            ->assertJsonCount(30, 'data.daily');

        $today = collect($report->json('data.daily'))->firstWhere('date', Market::today());
        $this->assertSame(['date' => Market::today(), 'orders' => 2, 'gross' => '14.00'], $today);
    }

    public function test_periods_are_local_dates_and_at_most_a_year(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/farms/{$farm->id}/analytics?from=2026-10-10&to=2026-10-01")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'The to field must be a date after or equal to from.']);
        $this->getJson("/api/v1/farms/{$farm->id}/analytics?from=2024-01-01&to=2026-01-01")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose a period of at most a year.');
        $this->getJson("/api/v1/farms/{$farm->id}/analytics?from=2026-10-01&to=2026-10-07")
            ->assertOk()
            ->assertJsonCount(7, 'data.daily');
    }

    public function test_managers_cannot_see_the_money(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $manager = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($manager->id, ['role' => FarmMemberRole::Manager->value]);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/farms/{$farm->id}/analytics")->assertForbidden();
    }
}
