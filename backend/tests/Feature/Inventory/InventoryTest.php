<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Market;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    private function adjust(InventoryBatch $batch, string $direction, string $quantity, string $reason, ?string $note = null)
    {
        return $this->postJson('/api/v1/inventory/adjustments', array_filter([
            'batch_id' => $batch->id,
            'direction' => $direction,
            'quantity' => $quantity,
            'reason' => $reason,
            'note' => $note,
        ]));
    }

    public function test_receiving_stock_creates_traceable_batches(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);
        $prefix = 'B'.Carbon::parse(Market::today())->format('ymd');

        $this->postJson("/api/v1/products/{$product->id}/batches", ['quantity' => '250', 'harvested_on' => Market::today()])
            ->assertCreated()
            ->assertJsonPath('data.code', "{$prefix}-01")
            ->assertJsonPath('data.quantity_on_hand', '250');
        $this->postJson("/api/v1/products/{$product->id}/batches", ['quantity' => '120.5'])
            ->assertJsonPath('data.code', "{$prefix}-02");

        $product->refresh();
        $this->assertSame('370.500', $product->stock_on_hand);
        $this->assertSame(Market::today(), $product->last_harvested_on->toDateString());

        $this->getJson("/api/v1/products/{$product->id}/stock-movements")
            ->assertJsonPath('data.0.quantity', '120.5')
            ->assertJsonPath('data.0.balance_after', '370.5')
            ->assertJsonPath('data.0.actor.name', $farmer->name)
            ->assertJsonPath('data.1.batch.code', "{$prefix}-01");
    }

    public function test_spoilage_reduces_the_batch_and_the_product(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withStock('100')->create(['farm_id' => $farm->id]);
        $batch = $product->batches()->sole();
        Sanctum::actingAs($farmer);

        $this->adjust($batch, 'decrease', '15', 'spoilage')
            ->assertCreated()
            ->assertJsonPath('data.quantity', '-15')
            ->assertJsonPath('data.balance_after', '85')
            ->assertJsonPath('data.reason', 'spoilage');

        $this->assertSame('85.000', $batch->fresh()->quantity_on_hand);
        $this->assertSame('85.000', $product->fresh()->stock_on_hand);

        $this->adjust($batch, 'increase', '5', 'count_correction')->assertJsonPath('data.balance_after', '90');
    }

    public function test_a_batch_cannot_go_below_zero(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withStock('100')->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->adjust($product->batches()->sole(), 'decrease', '150', 'damage')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only 100 kilograms left in batch '.$product->batches()->sole()->code.'.');

        $this->assertSame('100.000', $product->fresh()->stock_on_hand);
    }

    public function test_adjustment_rules(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $eggs = Product::factory()->withVariant('tray', '4.50')->withStock('40')->create([
            'farm_id' => $farm->id,
            'stock_unit_id' => Unit::idFor('tray'),
        ]);
        $batch = $eggs->batches()->sole();
        Sanctum::actingAs($farmer);

        $this->adjust($batch, 'increase', '2', 'spoilage')->assertJsonValidationErrors('direction');
        $this->adjust($batch, 'decrease', '1.5', 'damage')->assertJsonValidationErrors('quantity');
        $this->adjust($batch, 'decrease', '1', 'other')->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/products/{$eggs->id}/batches", ['quantity' => '2.5'])->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/inventory/adjustments', ['batch_id' => 'nope', 'direction' => 'decrease', 'quantity' => '1', 'reason' => 'damage'])
            ->assertJsonValidationErrors('batch_id');
    }

    public function test_only_the_farm_can_change_its_stock(): void
    {
        $product = Product::factory()->listed()->create();
        [$outsider] = $this->farmerWithFarm();
        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/products/{$product->id}/batches", ['quantity' => '10'])->assertForbidden();
        $this->adjust($product->batches()->sole(), 'decrease', '10', 'theft')->assertForbidden();
        $this->getJson("/api/v1/products/{$product->id}/stock-movements")->assertForbidden();
    }

    public function test_the_database_refuses_negative_stock(): void
    {
        $product = Product::factory()->withStock('5')->create();

        $this->expectException(QueryException::class);

        DB::table('products')->where('id', $product->id)->update(['stock_on_hand' => -1]);
    }

    public function test_low_stock_is_flagged_and_filterable(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $low = Product::factory()->withVariant()->withStock('40')->create(['farm_id' => $farm->id, 'low_stock_threshold' => '50']);
        Product::factory()->withVariant()->withStock('100')->create(['farm_id' => $farm->id, 'low_stock_threshold' => '50']);
        Product::factory()->withVariant()->withStock('1')->create(['farm_id' => $farm->id]); // no threshold set
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/farms/{$farm->id}/products?low_stock=1")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $low->id)
            ->assertJsonPath('data.0.stock.is_low', true);
    }
}
