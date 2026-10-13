<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalog\VariantSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit conversion, whole-number units and bulk price tiers for selling options.
 */
class VariantSpecTest extends TestCase
{
    use RefreshDatabase;

    private function spec(array $input, string $stockUnit = 'kg'): VariantSpec
    {
        return new VariantSpec($input, Unit::findByCode($stockUnit));
    }

    public function test_selling_in_the_stock_unit_uses_one_unit_of_stock(): void
    {
        $spec = $this->spec(['unit' => 'kg', 'price' => '1.20']);

        $this->assertTrue($spec->passes());
        $this->assertSame('1.000', $spec->attributes()['base_quantity']);
        $this->assertSame(120, $spec->attributes()['price_minor']);
        $this->assertSame('1.000', $spec->attributes()['min_order_quantity']);
    }

    public function test_metric_units_convert_automatically(): void
    {
        $this->assertSame('1000.000', $this->spec(['unit' => 'tonne', 'price' => '900'])->attributes()['base_quantity']);
        $this->assertSame('0.001', $this->spec(['unit' => 'g', 'price' => '0.01'])->attributes()['base_quantity']);
    }

    public function test_a_given_conversion_must_match_the_real_one(): void
    {
        $this->assertArrayHasKey('base_quantity', $this->spec(['unit' => 'kg', 'price' => '1', 'base_quantity' => '2'])->errors());
        $this->assertArrayHasKey('base_quantity', $this->spec(['unit' => 'tonne', 'price' => '1', 'base_quantity' => '900'])->errors());
    }

    public function test_units_too_small_for_the_stock_unit_are_refused(): void
    {
        $this->assertArrayHasKey('unit', $this->spec(['unit' => 'g', 'price' => '0.01'], 'tonne')->errors());
    }

    public function test_packages_need_their_contents(): void
    {
        $this->assertSame(
            ['base_quantity' => 'Say how many kilograms are in one crate.'],
            $this->spec(['unit' => 'crate', 'price' => '30'])->errors(),
        );

        $this->assertSame('28.000', $this->spec(['unit' => 'crate', 'price' => '30', 'base_quantity' => '28'])->attributes()['base_quantity']);
    }

    public function test_count_units_stay_whole(): void
    {
        // A bag of cabbages holds whole heads.
        $this->assertArrayHasKey('base_quantity', $this->spec(['unit' => 'bag', 'price' => '5', 'base_quantity' => '10.5'], 'head')->errors());
        $this->assertTrue($this->spec(['unit' => 'bag', 'price' => '5', 'base_quantity' => '10'], 'head')->passes());

        // Crates are ordered whole; kilograms can be fractional.
        $this->assertArrayHasKey('min_order_quantity', $this->spec(['unit' => 'crate', 'price' => '30', 'base_quantity' => '28', 'min_order_quantity' => '1.5'])->errors());
        $this->assertTrue($this->spec(['unit' => 'kg', 'price' => '1', 'min_order_quantity' => '0.5'])->passes());
    }

    public function test_maximum_order_cannot_be_below_minimum(): void
    {
        $errors = $this->spec(['unit' => 'kg', 'price' => '1', 'min_order_quantity' => '10', 'max_order_quantity' => '5'])->errors();

        $this->assertArrayHasKey('max_order_quantity', $errors);
    }

    public function test_bulk_tiers_are_sorted_and_must_get_cheaper(): void
    {
        $spec = $this->spec(['unit' => 'kg', 'price' => '30', 'price_tiers' => [
            ['min_quantity' => '20', 'price' => '25'],
            ['min_quantity' => '10', 'price' => '27'],
        ]]);

        $this->assertTrue($spec->passes());
        $this->assertSame([
            ['min_quantity' => '10.000', 'price_minor' => 2700],
            ['min_quantity' => '20.000', 'price_minor' => 2500],
        ], $spec->tiers());
    }

    public function test_bulk_tier_errors_point_at_the_clients_own_index(): void
    {
        // Sorted, 10 @ 28 is fine but 20 @ 29 is dearer than it; the client sent 20 @ 29 first.
        $errors = $this->spec(['unit' => 'kg', 'price' => '30', 'price_tiers' => [
            ['min_quantity' => '20', 'price' => '29'],
            ['min_quantity' => '10', 'price' => '28'],
        ]])->errors();

        $this->assertSame(['price_tiers.0.price'], array_keys($errors));
    }

    public function test_bulk_tiers_must_start_above_the_minimum_order_and_cost_less_than_the_base_price(): void
    {
        $errors = $this->spec(['unit' => 'kg', 'price' => '30', 'min_order_quantity' => '10', 'price_tiers' => [
            ['min_quantity' => '10', 'price' => '31'],
        ]])->errors();

        $this->assertArrayHasKey('price_tiers.0.min_quantity', $errors);
        $this->assertArrayHasKey('price_tiers.0.price', $errors);
    }

    public function test_updating_the_price_rechecks_existing_tiers(): void
    {
        $product = Product::factory()->withVariant('kg', '30.00')->create();
        $variant = $product->variants()->first();
        $variant->priceTiers()->create(['min_quantity' => '10', 'price_minor' => 2700]);

        $spec = new VariantSpec(['price' => '26.00'], $product->stockUnit, $variant->load(['unit', 'priceTiers']));

        $this->assertArrayHasKey('price_tiers', $spec->errors());
        $this->assertNull((new VariantSpec(['label' => 'Loose'], $product->stockUnit, $variant))->tiers(), 'Tiers left alone unless sent');
    }

    public function test_variants_report_what_stock_can_fill_and_price_by_tier(): void
    {
        $product = Product::factory()
            ->withVariant('kg', '1.20')
            ->withVariant('crate', '30.00', '28')
            ->withStock('100')
            ->create()
            ->fresh(); // stock was added through the inventory service after the factory built this instance
        [$kg, $crate] = $product->variants()->with('unit')->get()->all();
        $crate->priceTiers()->createMany([
            ['min_quantity' => '20', 'price_minor' => 2500],
            ['min_quantity' => '10', 'price_minor' => 2700],
        ]);
        $crate->load('priceTiers');

        $this->assertSame('100.000', (string) $kg->availableQuantity());
        $this->assertSame('3.000', (string) $crate->availableQuantity(), '100 kg fills 3 whole 28 kg crates');

        $product->forceFill(['stock_reserved' => '30'])->save();
        $this->assertSame('2.000', (string) $crate->fresh()->availableQuantity());

        $this->assertSame(3000, $crate->unitPriceMinorFor('9'));
        $this->assertSame(2700, $crate->unitPriceMinorFor('10'));
        $this->assertSame(2500, $crate->unitPriceMinorFor('25'));
    }
}
