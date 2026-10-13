<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money is stored as integer minor units (cents) and exchanged with clients as decimal strings ("30.50"),
 * so no amount ever passes through a float.
 */
final class Money
{
    /** Every currency in our markets (USD, ZWG, ZAR, ...) has two decimal places. */
    public const SCALE = 2;

    /**
     * "30.5" -> 3050. Expects input that has passed DecimalString validation.
     */
    public static function toMinor(BigDecimal|string|int|float $amount): int
    {
        return BigDecimal::of(is_float($amount) ? (string) $amount : $amount)
            ->toScale(self::SCALE)
            ->getUnscaledValue()
            ->toInt();
    }

    /**
     * 3050 -> "30.50".
     */
    public static function format(int $minor): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, self::SCALE);
    }

    /**
     * For messages people read: 3050 USD -> "$30.50", 3050 ZWG -> "ZWG 30.50".
     */
    public static function display(int $minor, string $currency): string
    {
        return ($currency === 'USD' ? '$' : $currency.' ').self::format($minor);
    }

    /**
     * Unit price times a (possibly fractional) quantity, rounded half up to the cent.
     */
    public static function multiply(int $minor, BigDecimal|string $quantity): int
    {
        return BigDecimal::of($minor)->multipliedBy($quantity)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * A share of an amount, rounded half up to the cent. The rate is a decimal string: "0.05" is 5%.
     */
    public static function percentage(int $minor, string $rate): int
    {
        return self::multiply($minor, $rate);
    }
}
