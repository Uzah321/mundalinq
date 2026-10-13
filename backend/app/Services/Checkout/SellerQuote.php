<?php

namespace App\Services\Checkout;

use App\Enums\FulfilmentMethod;
use App\Models\Farm;

/**
 * One farm's part of a quote: it becomes one seller order.
 */
final readonly class SellerQuote
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<FulfilmentMethod>  $availableMethods
     * @param  list<array{code: string, message: string}>  $problems  Problems with the farm group as a whole.
     */
    public function __construct(
        public Farm $farm,
        public array $lines,
        public array $availableMethods = [],
        public ?FulfilmentMethod $method = null,
        public int $deliveryFeeMinor = 0,
        public array $problems = [],
    ) {}

    public function subtotalMinor(): int
    {
        return array_sum(array_map(fn (QuoteLine $line) => $line->lineTotalMinor, $this->lines));
    }

    public function totalMinor(): int
    {
        return $this->subtotalMinor() + $this->deliveryFeeMinor;
    }
}
