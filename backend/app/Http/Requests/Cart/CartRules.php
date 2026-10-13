<?php

namespace App\Http\Requests\Cart;

use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Support\MessageBag;

/**
 * Checks for putting a quantity of a selling option in the cart. Checkout re-checks everything.
 */
final class CartRules
{
    /**
     * @param  string  $availabilityField  Field to report "not available" problems against.
     */
    public static function check(MessageBag $errors, ?ProductVariant $variant, BigDecimal $quantity, User $buyer, string $availabilityField): void
    {
        $product = $variant?->product;

        if ($variant === null || ! $variant->is_active || $product === null || ! $product->isVisibleToBuyers()) {
            $errors->add($availabilityField, __('This item is no longer available.'));

            return;
        }

        if ($product->farm->memberRole($buyer) !== null) {
            $errors->add($availabilityField, __('You cannot buy from your own farm.'));

            return;
        }

        $unit = $variant->unit;

        if (! $unit->allows_fraction && ! Quantity::isWhole($quantity)) {
            $errors->add('quantity', __('Order whole :units.', ['units' => $unit->plural_name]));
        } elseif ($quantity->isLessThan($variant->min_order_quantity)) {
            $errors->add('quantity', __('The minimum order is :quantity :unit.', [
                'quantity' => Quantity::format($variant->min_order_quantity),
                'unit' => $unit->nameFor($variant->min_order_quantity),
            ]));
        } elseif ($variant->max_order_quantity !== null && $quantity->isGreaterThan($variant->max_order_quantity)) {
            $errors->add('quantity', __('The maximum order is :quantity :unit.', [
                'quantity' => Quantity::format($variant->max_order_quantity),
                'unit' => $unit->nameFor($variant->max_order_quantity),
            ]));
        } elseif ($quantity->isGreaterThan($variant->availableQuantity())) {
            $available = $variant->availableQuantity();
            $errors->add('quantity', __('Only :quantity :unit available.', [
                'quantity' => Quantity::format($available),
                'unit' => $unit->nameFor($available),
            ]));
        }
    }

    public static function variant(string $id): ?ProductVariant
    {
        return ProductVariant::with(['unit', 'product.farm'])->find($id);
    }
}
