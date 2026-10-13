<?php

namespace App\Rules;

use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive decimal with a fixed maximum number of decimal places, e.g. a price or a stock quantity.
 * Clients should send these as strings ("12.50"); plain JSON numbers are accepted when unambiguous.
 */
final class DecimalString implements ValidationRule
{
    private bool $allowZero = false;

    private function __construct(private int $scale, private string $max) {}

    public static function money(): self
    {
        return new self(Money::SCALE, '99999999.99');
    }

    public static function quantity(): self
    {
        return new self(Quantity::SCALE, '99999999999.999');
    }

    /**
     * Also accept zero, e.g. free delivery, or "none of this item" when confirming an order.
     */
    public function allowingZero(): self
    {
        $rule = clone $this;
        $rule->allowZero = true;

        return $rule;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $string = is_int($value) || is_float($value) ? (string) $value : $value;

        if (! is_string($string) || ! preg_match('/^\d{1,12}(\.\d{1,'.$this->scale.'})?$/', $string)) {
            $fail(__('The :attribute must be a positive number with at most :scale decimal places.', ['scale' => $this->scale]));

            return;
        }

        $decimal = BigDecimal::of($string);

        if ($decimal->isZero() && ! $this->allowZero) {
            $fail(__('The :attribute must be greater than zero.'));
        } elseif ($decimal->isGreaterThan($this->max)) {
            $fail(__('The :attribute is too large.'));
        }
    }
}
