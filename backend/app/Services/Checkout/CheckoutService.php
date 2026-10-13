<?php

namespace App\Services\Checkout;

use App\Enums\FulfilmentMethod;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderNotifier;
use App\Support\Money;
use App\Support\OrderNumber;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private QuoteBuilder $quotes,
        private InventoryService $inventory,
        private OrderNotifier $notifier,
    ) {}

    /**
     * Turn the buyer's cart into an order, one seller order per farm, and reserve the stock.
     *
     * @param  array<string, string>  $methods  Farm id => "pickup" or "delivery".
     * @param  string  $expectedTotal  The total the buyer agreed to; the order is refused if prices moved since.
     */
    public function placeOrder(User $buyer, ?UserAddress $address, array $methods, string $expectedTotal, ?string $note = null): Order
    {
        $order = DB::transaction(function () use ($buyer, $address, $methods, $expectedTotal, $note) {
            // One checkout per buyer at a time: a double-tapped "Place order" waits here, then finds the cart empty.
            $cart = Cart::where('user_id', $buyer->id)->lockForUpdate()->first();
            $quote = $cart ? $this->quotes->build($cart, $buyer, true, $address, $methods) : null;

            if ($quote === null || ! $quote->canPlaceOrder()) {
                throw new BusinessRuleException(__('Some items in your cart need attention before you can order.'), 422, [
                    'problems' => $quote?->allProblems() ?? [['code' => 'cart_empty', 'message' => __('Your cart is empty.'), 'farm_id' => null, 'cart_item_id' => null]],
                ]);
            }

            if ($quote->totalMinor() !== Money::toMinor($expectedTotal)) {
                throw new BusinessRuleException(__('Prices or delivery charges have changed. Check the new total and try again.'), 409, [
                    'total' => Money::format($quote->totalMinor()),
                ]);
            }

            // The quote checked stock without locks; this re-checks it under lock and claims it.
            $this->inventory->reserve($quote->stockByProduct());

            $order = $this->createOrder($buyer, $quote, $address, $note);
            $cart->items()->whereKey($quote->cartItemIds())->delete();

            return $order;
        });

        $this->notifier->orderPlaced($order);

        return $order;
    }

    private function createOrder(User $buyer, Quote $quote, ?UserAddress $address, ?string $note): Order
    {
        $order = Order::forceCreate([
            'number' => OrderNumber::generate(),
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::AwaitingConfirmation,
            'currency' => $quote->currency,
            'subtotal_minor' => $quote->subtotalMinor(),
            'delivery_fee_minor' => $quote->deliveryFeeMinor(),
            'total_minor' => $quote->totalMinor(),
            'note' => $note,
        ]);

        OrderStatusChange::record($order, null, null, OrderStatus::AwaitingConfirmation, $buyer);

        $confirmBy = now()->addMinutes(config('mundalinq.orders.confirm_within_minutes'));

        foreach ($quote->sellers as $index => $seller) {
            $sellerOrder = $order->sellerOrders()->forceCreate([
                'farm_id' => $seller->farm->id,
                'number' => $order->number.'-'.($index + 1),
                'status' => SellerOrderStatus::AwaitingConfirmation,
                'fulfilment_method' => $seller->method,
                'delivery_address' => $seller->method === FulfilmentMethod::Delivery ? $address->snapshot() : null,
                'subtotal_minor' => $seller->subtotalMinor(),
                'delivery_fee_minor' => $seller->deliveryFeeMinor,
                'total_minor' => $seller->totalMinor(),
                'handover_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'confirm_by' => $confirmBy,
            ]);

            OrderStatusChange::record($order, $sellerOrder, null, SellerOrderStatus::AwaitingConfirmation, $buyer);

            foreach ($seller->lines as $line) {
                $variant = $line->variant;

                $sellerOrder->items()->forceCreate([
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_title' => $variant->product->title,
                    'variant_label' => $variant->label,
                    'unit_code' => $variant->unit->code,
                    'unit_name' => $variant->unit->name,
                    'unit_plural_name' => $variant->unit->plural_name,
                    'base_quantity' => $variant->base_quantity,
                    'quantity' => (string) $line->quantity,
                    'unit_price_minor' => $line->unitPriceMinor,
                    'line_total_minor' => $line->lineTotalMinor,
                    'stock_quantity' => (string) $line->stockQuantity,
                ]);
            }
        }

        return $order;
    }
}
