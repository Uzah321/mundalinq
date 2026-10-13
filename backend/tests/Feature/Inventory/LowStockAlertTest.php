<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_the_farm_is_alerted_once_when_orders_take_a_listing_down_to_its_low_level(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm, '20', 'kg', '1.20', ['title' => 'Roma tomatoes', 'low_stock_threshold' => '15']);
        $alerts = fn () => array_values(array_filter($this->smsTo($farmer->phone), fn (string $sms) => str_contains($sms, 'is down to')));

        $this->addToCart($first = $this->buyer(), $variant, '3');
        $this->checkout($first);
        $this->assertSame([], $alerts());

        $this->addToCart($second = $this->buyer(), $variant, '3');
        $this->checkout($second);
        $this->assertSame(['Roma tomatoes is down to 14 kilograms. Add stock in the MundaLinq app so buyers can keep ordering.'], $alerts());
        $this->assertSame('Running low: Roma tomatoes', $farmer->notifications()->where('type', 'listings')->value('data')['title']);

        $this->addToCart($third = $this->buyer(), $variant, '1');
        $this->checkout($third);
        $this->assertCount(1, $alerts());
    }

    public function test_writing_off_stock_can_trigger_the_alert(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = $this->listing($farm, '20', 'kg', '1.20', ['title' => 'Covo', 'low_stock_threshold' => '5'])->product;
        Sanctum::actingAs($farmer);

        $this->postJson('/api/v1/inventory/adjustments', [
            'batch_id' => $product->batches()->value('id'),
            'direction' => 'decrease',
            'quantity' => '16',
            'reason' => 'spoilage',
        ])->assertCreated();

        $this->assertStringContainsString('Covo is down to 4 kilograms', last($this->smsTo($farmer->phone)));
    }
}
