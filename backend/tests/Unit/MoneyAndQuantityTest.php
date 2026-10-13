<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\Exception\RoundingNecessaryException;
use PHPUnit\Framework\TestCase;

class MoneyAndQuantityTest extends TestCase
{
    public function test_money_converts_between_decimal_strings_and_cents(): void
    {
        $this->assertSame(3050, Money::toMinor('30.5'));
        $this->assertSame(3050, Money::toMinor('30.50'));
        $this->assertSame(120, Money::toMinor(1.2));
        $this->assertSame(500, Money::toMinor(5));
        $this->assertSame('30.50', Money::format(3050));
        $this->assertSame('0.05', Money::format(5));
    }

    public function test_money_never_silently_rounds(): void
    {
        $this->expectException(RoundingNecessaryException::class);

        Money::toMinor('1.005');
    }

    public function test_quantities_are_formatted_without_padding(): void
    {
        $this->assertSame('28', Quantity::format('28.000'));
        $this->assertSame('0.5', Quantity::format('0.500'));
        $this->assertSame('0', Quantity::format('0.000'));
        $this->assertSame('-15', Quantity::format('-15.000'));
        $this->assertNull(Quantity::format(null));
    }

    public function test_whole_quantities_are_detected(): void
    {
        $this->assertTrue(Quantity::isWhole(Quantity::of('12')));
        $this->assertTrue(Quantity::isWhole(Quantity::of('12.000')));
        $this->assertFalse(Quantity::isWhole(Quantity::of('12.5')));
    }
}
