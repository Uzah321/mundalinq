<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Stock and order quantities: fixed-point with three decimals (grams when counting in kg).
 */
final class Quantity
{
    public const SCALE = 3;

    /**
     * Expects a database value or input that has passed DecimalString validation.
     */
    public static function of(BigDecimal|string|int|float $value): BigDecimal
    {
        return BigDecimal::of(is_float($value) ? (string) $value : $value)->toScale(self::SCALE);
    }

    public static function isWhole(BigDecimal $value): bool
    {
        return $value->getFractionalPart()->isZero();
    }

    /**
     * Client-facing form without padding: "28.000" -> "28", "0.500" -> "0.5".
     */
    public static function format(BigDecimal|string|null $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }
}
