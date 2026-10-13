<?php

namespace App\Services\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Settlement\SettlementService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The buyer's side of an order and everything that moves the order as a whole.
 *
 * Lock order is always: order, then seller order, then products. Methods marked "locked" expect the
 * caller to hold the order lock inside a transaction.
 */
class OrderService
{
    public function __construct(
        private InventoryService $inventory,
        private SettlementService $settlement,
        private OrderNotifier $notifier,
    ) {}

    /**
     * Locked. Once every farm has answered, ask the buyer to pay for what was accepted, or cancel.
     */
    public function afterFarmDecision(Order $order): void
    {
        $sellerOrders = $order->sellerOrders()->get();

        if ($sellerOrders->contains(fn (SellerOrder $sellerOrder) => $sellerOrder->status === SellerOrderStatus::AwaitingConfirmation)) {
            return;
        }

        $accepted = $sellerOrders->filter(fn (SellerOrder $sellerOrder) => $sellerOrder->status === SellerOrderStatus::Accepted);

        if ($accepted->isEmpty()) {
            $this->cancel($order, CancellationReason::NotConfirmed);

            return;
        }

        $order->forceFill([
            'subtotal_minor' => $accepted->sum('subtotal_minor'),
            'delivery_fee_minor' => $accepted->sum('delivery_fee_minor'),
            'discount_minor' => $accepted->sum('discount_minor'),
            'tax_minor' => $accepted->sum('tax_minor'),
            'total_minor' => $accepted->sum('total_minor'),
            'payment_due_at' => now()->addMinutes(config('mundalinq.orders.pay_within_minutes')),
        ]);
        $order->transitionTo(OrderStatus::AwaitingPayment);

        DB::afterCommit(fn () => $this->notifier->readyToPay($order));
    }

    public function cancelByBuyer(Order $order, User $buyer): Order
    {
        return DB::transaction(function () use ($order, $buyer) {
            $order = $this->lock($order);

            if (! $order->status->canBeCancelledByBuyer()) {
                throw new BusinessRuleException(__('This order can no longer be cancelled.'));
            }

            // Cancelling under a live payment prompt could take the money for a cancelled order.
            if ($order->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new BusinessRuleException(__('A payment is in progress. Wait for it to finish before cancelling.'));
            }

            $this->cancel($order, CancellationReason::BuyerCancelled, $buyer);

            return $order;
        });
    }

    /**
     * Locked. Cancel an order that has not been paid, giving back any stock it still holds.
     */
    public function cancel(Order $order, CancellationReason $reason, ?User $actor = null): void
    {
        foreach ($order->sellerOrders()->get() as $sellerOrder) {
            if ($sellerOrder->status === SellerOrderStatus::AwaitingConfirmation || $sellerOrder->status === SellerOrderStatus::Accepted) {
                $this->releaseStock($sellerOrder);
                $sellerOrder->transitionTo(SellerOrderStatus::Cancelled, $actor, $reason->value);
            }
        }

        $order->cancellation_reason = $reason;
        $order->transitionTo(OrderStatus::Cancelled, $actor, $reason->value);

        DB::afterCommit(fn () => $this->notifier->orderCancelled($order));
    }

    /**
     * Locked. The provider confirmed payment: farms can start preparing, and their earnings are recorded.
     */
    public function markPaid(Order $order, Payment $payment): void
    {
        $order->transitionTo(OrderStatus::Paid);

        foreach ($order->sellerOrders()->get() as $sellerOrder) {
            if ($sellerOrder->status === SellerOrderStatus::Accepted) {
                $sellerOrder->transitionTo(SellerOrderStatus::Preparing);
            }
        }

        $this->settlement->recordSale($order, $payment);

        DB::afterCommit(fn () => $this->notifier->orderPaid($order, $payment));
    }

    /**
     * The buyer confirms the goods arrived as expected, which makes the sale final straight away.
     */
    public function confirmReceipt(SellerOrder $sellerOrder, User $buyer): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $buyer) {
            $order = $this->lock($sellerOrder->order_id);
            $sellerOrder = SellerOrder::whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();

            if ($sellerOrder->status !== SellerOrderStatus::Delivered) {
                throw new BusinessRuleException(__('This order has not been handed over yet.'));
            }

            $this->complete($order, $sellerOrder, $buyer);

            return $sellerOrder;
        });
    }

    /**
     * Locked. The sale is final: release the farm's earnings, and close the order once nothing is open.
     */
    public function complete(Order $order, SellerOrder $sellerOrder, ?User $actor = null): void
    {
        $sellerOrder->transitionTo(SellerOrderStatus::Completed, $actor);
        $this->settlement->makeAvailable($sellerOrder);

        $open = $order->sellerOrders()->get()->contains(
            fn (SellerOrder $other) => $other->status !== SellerOrderStatus::Completed && ! $other->status->isDropped()
        );

        if (! $open && $order->status === OrderStatus::Paid) {
            $order->transitionTo(OrderStatus::Completed);
        }
    }

    /**
     * Give back the stock a seller order holds (before handover).
     */
    public function releaseStock(SellerOrder $sellerOrder): void
    {
        $quantities = [];

        foreach ($sellerOrder->items()->get() as $item) {
            $stock = Quantity::of($item->stock_quantity);

            if ($stock->isPositive()) {
                $quantities[$item->product_id] = ($quantities[$item->product_id] ?? Quantity::of(0))->plus($stock);
            }
        }

        if ($quantities !== []) {
            $this->inventory->release($quantities);
        }
    }

    /**
     * Scheduled: farms that did not answer in time lose the order and its stock is freed.
     */
    public function expireUnconfirmed(): int
    {
        $expired = 0;

        $overdue = SellerOrder::where('status', SellerOrderStatus::AwaitingConfirmation)
            ->where('confirm_by', '<=', now())
            ->pluck('order_id', 'id');

        foreach ($overdue as $sellerOrderId => $orderId) {
            DB::transaction(function () use ($sellerOrderId, $orderId, &$expired) {
                $order = $this->lock($orderId);
                $sellerOrder = SellerOrder::whereKey($sellerOrderId)->lockForUpdate()->firstOrFail();

                // Re-check under the lock: the farm may have answered meanwhile.
                if ($sellerOrder->status !== SellerOrderStatus::AwaitingConfirmation || $sellerOrder->confirm_by->isFuture()) {
                    return;
                }

                $this->releaseStock($sellerOrder);
                $sellerOrder->transitionTo(SellerOrderStatus::Expired, note: 'not_answered_in_time');
                $this->afterFarmDecision($order);
                $expired++;
            });
        }

        return $expired;
    }

    /**
     * Scheduled: cancel an order whose payment time has passed, unless a payment has arrived.
     */
    public function cancelIfUnpaid(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::AwaitingPayment || $order->payment_due_at->isFuture()) {
                return false;
            }

            $this->cancel($order, CancellationReason::PaymentOverdue);

            return true;
        });
    }

    /**
     * Scheduled: handed-over orders nobody disputed become final after the dispute window.
     */
    public function completeUndisputed(): int
    {
        $completed = 0;

        $due = SellerOrder::where('status', SellerOrderStatus::Delivered)
            ->where('delivered_at', '<=', now()->subHours(config('mundalinq.orders.dispute_window_hours')))
            ->pluck('order_id', 'id');

        foreach ($due as $sellerOrderId => $orderId) {
            DB::transaction(function () use ($sellerOrderId, $orderId, &$completed) {
                $order = $this->lock($orderId);
                $sellerOrder = SellerOrder::whereKey($sellerOrderId)->lockForUpdate()->firstOrFail();

                if ($sellerOrder->status === SellerOrderStatus::Delivered) {
                    $this->complete($order, $sellerOrder);
                    $completed++;
                }
            });
        }

        return $completed;
    }

    public function lock(Order|string $order): Order
    {
        return Order::whereKey($order instanceof Order ? $order->id : $order)->lockForUpdate()->firstOrFail();
    }
}
