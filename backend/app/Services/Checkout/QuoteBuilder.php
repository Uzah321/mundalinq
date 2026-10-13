<?php

namespace App\Services\Checkout;

use App\Enums\FulfilmentMethod;
use App\Models\Cart;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Geo;
use App\Support\Market;
use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Prices a cart with current listings: unit prices with bulk tiers, stock, fulfilment and delivery fees.
 */
class QuoteBuilder
{
    /**
     * @param  bool  $forCheckout  Also work out pickup/delivery and fees (the cart view skips them).
     * @param  array<string, string>  $methods  Farm id => "pickup" or "delivery"; other farms get their default.
     */
    public function build(Cart $cart, User $buyer, bool $forCheckout = false, ?UserAddress $address = null, array $methods = []): Quote
    {
        $cart->load([
            'items.variant.unit',
            'items.variant.priceTiers',
            'items.variant.product' => fn ($product) => $product->withTrashed(),
            'items.variant.product.farm' => fn ($farm) => $farm->withTrashed(),
            'items.variant.product.location',
            'items.variant.product.stockUnit',
            'items.variant.product.coverImage',
        ]);

        if ($cart->items->isEmpty()) {
            return new Quote(Market::currency(), [], [self::problem('cart_empty', __('Your cart is empty.'))]);
        }

        $ownFarmIds = $buyer->farms()->pluck('farms.id')->all();
        $lines = [];

        foreach ($cart->items as $item) {
            $variant = $item->variant;
            $quantity = Quantity::of($item->quantity);
            $unitPrice = $variant->unitPriceMinorFor($quantity);

            $line = new QuoteLine(
                $item,
                $variant,
                $quantity,
                $unitPrice,
                Money::multiply($unitPrice, $quantity),
                $quantity->multipliedBy($variant->base_quantity)->toScale(Quantity::SCALE, RoundingMode::Up),
            );
            $line->problem = $this->lineProblem($line, $ownFarmIds);
            $lines[] = $line;
        }

        $this->flagShortages($lines);

        $groups = [];
        foreach ($lines as $line) {
            $groups[$line->variant->product->farm_id][] = $line;
        }

        $sellers = array_map(
            fn (array $farmLines) => $forCheckout ? $this->checkoutGroup($farmLines, $address, $methods) : new SellerQuote($farmLines[0]->variant->product->farm, $farmLines),
            array_values($groups),
        );

        return new Quote(Market::currency(), $sellers);
    }

    /**
     * @param  list<string>  $ownFarmIds
     */
    private function lineProblem(QuoteLine $line, array $ownFarmIds): ?array
    {
        $variant = $line->variant;
        $product = $variant->product;
        $unit = $variant->unit;

        return match (true) {
            $variant->trashed() || ! $variant->is_active || $product->trashed() || ! $product->isVisibleToBuyers() => self::problem('unavailable', __('This item is no longer available.')),
            in_array($product->farm_id, $ownFarmIds, true) => self::problem('own_farm', __('You cannot buy from your own farm.')),
            ! $unit->allows_fraction && ! Quantity::isWhole($line->quantity) => self::problem('whole_units_only', __('Order whole :units.', ['units' => $unit->plural_name])),
            $line->quantity->isLessThan($variant->min_order_quantity) => self::problem('below_minimum', __('The minimum order is :quantity :unit.', [
                'quantity' => Quantity::format($variant->min_order_quantity),
                'unit' => $unit->nameFor($variant->min_order_quantity),
            ])),
            $variant->max_order_quantity !== null && $line->quantity->isGreaterThan($variant->max_order_quantity) => self::problem('above_maximum', __('The maximum order is :quantity :unit.', [
                'quantity' => Quantity::format($variant->max_order_quantity),
                'unit' => $unit->nameFor($variant->max_order_quantity),
            ])),
            default => null,
        };
    }

    /**
     * Compare stock per product, since a kg option and a crate option draw on the same stock.
     *
     * @param  list<QuoteLine>  $lines
     */
    private function flagShortages(array $lines): void
    {
        $byProduct = [];
        foreach ($lines as $line) {
            if ($line->problem === null) {
                $byProduct[$line->variant->product_id][] = $line;
            }
        }

        foreach ($byProduct as $productLines) {
            $product = $productLines[0]->variant->product;
            $needed = array_reduce($productLines, fn (BigDecimal $sum, QuoteLine $line) => $sum->plus($line->stockQuantity), Quantity::of(0));
            $available = $product->availableStock();

            if ($needed->isGreaterThan($available)) {
                $left = $available->isNegative() ? Quantity::of(0) : $available;

                foreach ($productLines as $line) {
                    $line->problem = self::problem('insufficient_stock', __('Only :quantity :unit left.', [
                        'quantity' => Quantity::format($left),
                        'unit' => $product->stockUnit->nameFor($left),
                    ]));
                }
            }
        }
    }

    /**
     * @param  list<QuoteLine>  $lines  One farm's lines.
     * @param  array<string, string>  $methods
     */
    private function checkoutGroup(array $lines, ?UserAddress $address, array $methods): SellerQuote
    {
        $farm = $lines[0]->variant->product->farm;
        $available = $this->availableMethods($lines);
        $requested = isset($methods[$farm->id]) ? FulfilmentMethod::tryFrom($methods[$farm->id]) : null;
        $method = $requested ?? ($available[0] ?? null);
        $problems = [];

        if ($method === null || ! in_array($method, $available, true)) {
            $problems[] = self::problem('fulfilment_unavailable', $method === null
                ? __(':farm cannot hand these items over together. Order them separately.', ['farm' => $farm->name])
                : __(':farm does not offer :method for these items.', ['farm' => $farm->name, 'method' => $method->value]));
        } elseif ($method === FulfilmentMethod::Delivery) {
            if ($address === null) {
                $problems[] = self::problem('address_required', __('Choose a delivery address.'));
            } elseif ($this->outsideDeliveryArea($lines, $address)) {
                $problems[] = self::problem('outside_delivery_area', __(':farm does not deliver to this address.', ['farm' => $farm->name]));
            }
        }

        $subtotal = array_sum(array_map(fn (QuoteLine $line) => $line->lineTotalMinor, $lines));
        $fee = $method === FulfilmentMethod::Delivery ? $farm->deliveryFeeFor($subtotal) : 0;

        return new SellerQuote($farm, $lines, $available, $method, $fee, $problems);
    }

    /**
     * Methods every item in the group allows; pickup first, as the cheaper default.
     *
     * @param  list<QuoteLine>  $lines
     * @return list<FulfilmentMethod>
     */
    private function availableMethods(array $lines): array
    {
        $methods = [];

        if (array_reduce($lines, fn (bool $all, QuoteLine $line) => $all && $line->variant->product->offersPickupToBuyers(), true)) {
            $methods[] = FulfilmentMethod::Pickup;
        }

        if (array_reduce($lines, fn (bool $all, QuoteLine $line) => $all && $line->variant->product->offersDeliveryToBuyers(), true)) {
            $methods[] = FulfilmentMethod::Delivery;
        }

        return $methods;
    }

    /**
     * @param  list<QuoteLine>  $lines
     */
    private function outsideDeliveryArea(array $lines, UserAddress $address): bool
    {
        foreach ($lines as $line) {
            $product = $line->variant->product;
            $radius = (float) $product->farm->delivery_radius_km;
            $distance = Geo::distanceKm($product->location->latitude, $product->location->longitude, $address->latitude, $address->longitude);

            if ($distance > $radius) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function problem(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
