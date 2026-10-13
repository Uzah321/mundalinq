<?php

namespace App\Services\Checkout;

use App\Support\Quantity;
use Brick\Math\BigDecimal;

/**
 * The cart priced from scratch: never trusted from the client, rebuilt on every request.
 */
final readonly class Quote
{
    /**
     * @param  list<SellerQuote>  $sellers
     * @param  list<array{code: string, message: string}>  $problems  Problems with the cart as a whole.
     */
    public function __construct(
        public string $currency,
        public array $sellers,
        public array $problems = [],
    ) {}

    public function subtotalMinor(): int
    {
        return array_sum(array_map(fn (SellerQuote $seller) => $seller->subtotalMinor(), $this->sellers));
    }

    public function deliveryFeeMinor(): int
    {
        return array_sum(array_map(fn (SellerQuote $seller) => $seller->deliveryFeeMinor, $this->sellers));
    }

    public function totalMinor(): int
    {
        return $this->subtotalMinor() + $this->deliveryFeeMinor();
    }

    /**
     * Every problem in one list, each tagged with the farm and cart item it concerns.
     *
     * @return list<array{code: string, message: string, farm_id: ?string, cart_item_id: ?string}>
     */
    public function allProblems(): array
    {
        $problems = array_map(fn (array $problem) => [...$problem, 'farm_id' => null, 'cart_item_id' => null], $this->problems);

        foreach ($this->sellers as $seller) {
            foreach ($seller->problems as $problem) {
                $problems[] = [...$problem, 'farm_id' => $seller->farm->id, 'cart_item_id' => null];
            }

            foreach ($seller->lines as $line) {
                if ($line->problem !== null) {
                    $problems[] = [...$line->problem, 'farm_id' => $seller->farm->id, 'cart_item_id' => $line->item->id];
                }
            }
        }

        return $problems;
    }

    public function canPlaceOrder(): bool
    {
        return $this->sellers !== [] && $this->allProblems() === [];
    }

    /**
     * Stock to reserve per product, adding up every selling option of the same product.
     *
     * @return array<string, BigDecimal>
     */
    public function stockByProduct(): array
    {
        $totals = [];

        foreach ($this->sellers as $seller) {
            foreach ($seller->lines as $line) {
                $productId = $line->variant->product_id;
                $totals[$productId] = ($totals[$productId] ?? Quantity::of(0))->plus($line->stockQuantity);
            }
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    public function cartItemIds(): array
    {
        $ids = [];

        foreach ($this->sellers as $seller) {
            foreach ($seller->lines as $line) {
                $ids[] = $line->item->id;
            }
        }

        return $ids;
    }
}
