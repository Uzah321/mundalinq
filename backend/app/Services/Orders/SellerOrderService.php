<?php

namespace App\Services\Orders;

use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * What a farm does with its part of an order: answer it, prepare it, hand it over.
 */
class SellerOrderService
{
    public function __construct(
        private OrderService $orders,
        private InventoryService $inventory,
        private OrderNotifier $notifier,
    ) {}

    /**
     * Accept, optionally supplying less of some items. Prices per unit stay as ordered; the buyer
     * sees the final total before paying.
     *
     * @param  array<string, string>  $confirmed  Order item id => quantity the farm can supply.
     */
    public function accept(SellerOrder $sellerOrder, User $actor, array $confirmed = []): SellerOrder
    {
        return $this->locked($sellerOrder, function (Order $order, SellerOrder $sellerOrder) use ($actor, $confirmed) {
            $this->ensureAwaitingAnswer($sellerOrder);

            $release = [];
            $subtotal = 0;
            $supplying = false;

            foreach ($sellerOrder->items()->get() as $item) {
                $quantity = Quantity::of($confirmed[$item->id] ?? $item->quantity);
                $stock = $quantity->multipliedBy($item->base_quantity)->toScale(Quantity::SCALE, RoundingMode::Up);
                $excess = Quantity::of($item->stock_quantity)->minus($stock);

                if ($excess->isPositive()) {
                    $release[$item->product_id] = ($release[$item->product_id] ?? Quantity::of(0))->plus($excess);
                }

                $lineTotal = Money::multiply($item->unit_price_minor, $quantity);
                $item->forceFill([
                    'confirmed_quantity' => (string) $quantity,
                    'stock_quantity' => (string) $stock,
                    'line_total_minor' => $lineTotal,
                ])->save();

                $subtotal += $lineTotal;
                $supplying = $supplying || $quantity->isPositive();
            }

            if (! $supplying) {
                throw new BusinessRuleException(__('Nothing would be supplied. Reject the order instead.'), 422);
            }

            if ($release !== []) {
                $this->inventory->release($release);
            }

            $sellerOrder->forceFill([
                'subtotal_minor' => $subtotal,
                'total_minor' => $subtotal + $sellerOrder->delivery_fee_minor - $sellerOrder->discount_minor + $sellerOrder->tax_minor,
            ]);
            $sellerOrder->transitionTo(SellerOrderStatus::Accepted, $actor);

            $this->orders->afterFarmDecision($order);
        });
    }

    public function reject(SellerOrder $sellerOrder, User $actor, string $reason): SellerOrder
    {
        return $this->locked($sellerOrder, function (Order $order, SellerOrder $sellerOrder) use ($actor, $reason) {
            $this->ensureAwaitingAnswer($sellerOrder);

            $this->orders->releaseStock($sellerOrder);
            $sellerOrder->rejection_reason = $reason;
            $sellerOrder->transitionTo(SellerOrderStatus::Rejected, $actor, $reason);

            $this->orders->afterFarmDecision($order);
        });
    }

    public function markReady(SellerOrder $sellerOrder, User $actor): SellerOrder
    {
        return $this->locked($sellerOrder, function (Order $order, SellerOrder $sellerOrder) use ($actor) {
            if ($sellerOrder->status !== SellerOrderStatus::Preparing) {
                throw new BusinessRuleException(__('Only paid orders being prepared can be marked ready.'));
            }

            $sellerOrder->transitionTo(SellerOrderStatus::Ready, $actor);

            DB::afterCommit(fn () => $this->notifier->sellerOrderReady($sellerOrder));
        });
    }

    /**
     * Delivery orders leave the farm: their stock is taken now.
     */
    public function dispatch(SellerOrder $sellerOrder, User $actor): SellerOrder
    {
        return $this->locked($sellerOrder, function (Order $order, SellerOrder $sellerOrder) use ($actor) {
            if ($sellerOrder->isPickup()) {
                throw new BusinessRuleException(__('Pickup orders are handed over at the farm, not dispatched.'), 422);
            }

            if ($sellerOrder->status !== SellerOrderStatus::Ready) {
                throw new BusinessRuleException(__('Mark the order ready before dispatching it.'));
            }

            $this->takeStock($sellerOrder, $actor);
            $sellerOrder->transitionTo(SellerOrderStatus::Dispatched, $actor);

            DB::afterCommit(fn () => $this->notifier->sellerOrderDispatched($sellerOrder));
        });
    }

    /**
     * The buyer received the goods, proven by the code from their app.
     */
    public function handOver(SellerOrder $sellerOrder, User $actor, string $code): SellerOrder
    {
        return $this->locked($sellerOrder, function (Order $order, SellerOrder $sellerOrder) use ($actor, $code) {
            $expected = $sellerOrder->isPickup() ? SellerOrderStatus::Ready : SellerOrderStatus::Dispatched;

            if ($sellerOrder->status !== $expected) {
                throw new BusinessRuleException($sellerOrder->isPickup()
                    ? __('Mark the order ready before handing it over.')
                    : __('Dispatch the order before confirming delivery.'));
            }

            if (! $sellerOrder->matchesHandoverCode($code)) {
                throw new BusinessRuleException(__('That code is not right. Ask the buyer for the code in their :app app.', ['app' => config('app.name')]), 422);
            }

            if ($sellerOrder->isPickup()) {
                $this->takeStock($sellerOrder, $actor);
            }

            $sellerOrder->transitionTo(SellerOrderStatus::Delivered, $actor);
        });
    }

    private function ensureAwaitingAnswer(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->status !== SellerOrderStatus::AwaitingConfirmation) {
            throw new BusinessRuleException(__('This order has already been answered.'));
        }

        if ($sellerOrder->confirm_by->isPast()) {
            throw new BusinessRuleException(__('The time to answer this order has passed.'));
        }
    }

    /**
     * Products are locked in id order, matching checkout, so the two cannot deadlock.
     */
    private function takeStock(SellerOrder $sellerOrder, User $actor): void
    {
        foreach ($sellerOrder->items()->get()->sortBy('product_id') as $item) {
            $this->inventory->consume($item, $actor);
        }
    }

    /**
     * Run a change with the order and seller order locked, always in that order.
     *
     * @param  callable(Order, SellerOrder): void  $change
     */
    private function locked(SellerOrder $sellerOrder, callable $change): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $change) {
            $order = $this->orders->lock($sellerOrder->order_id);
            $sellerOrder = SellerOrder::whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();

            $change($order, $sellerOrder);

            return $sellerOrder;
        });
    }
}
