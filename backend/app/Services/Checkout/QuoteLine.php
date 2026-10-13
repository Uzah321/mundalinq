<?php

namespace App\Services\Checkout;

use App\Models\CartItem;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;

final class QuoteLine
{
    /** @var array{code: string, message: string}|null */
    public ?array $problem = null;

    public function __construct(
        public readonly CartItem $item,
        public readonly ProductVariant $variant,
        public readonly BigDecimal $quantity,
        public readonly int $unitPriceMinor,
        public readonly int $lineTotalMinor,
        /** Stock units this line would hold, in the product's stock unit. */
        public readonly BigDecimal $stockQuantity,
    ) {}
}
