<?php

namespace App\Services\Orders;

use App\Enums\NotificationCategory;
use App\Models\Farm;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerOrder;
use App\Notifications\MarketplaceAlert;
use App\Support\Market;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Tells buyers and farms about their orders: in the app, and by SMS at the moments someone has to act.
 */
class OrderNotifier
{
    public function orderPlaced(Order $order): void
    {
        foreach ($order->sellerOrders()->with(['farm.members', 'items'])->get() as $sellerOrder) {
            $this->toFarm($sellerOrder, __('New order :number', ['number' => $sellerOrder->number]), __('New order :number on :app: :count item(s), :total. Accept or reject it in the app by :deadline.', [
                'number' => $sellerOrder->number,
                'app' => config('app.name'),
                'count' => $sellerOrder->items->count(),
                'total' => Money::display($sellerOrder->total_minor, $order->currency),
                'deadline' => $this->time($sellerOrder->confirm_by),
            ]));
        }
    }

    public function readyToPay(Order $order): void
    {
        $partial = $order->sellerOrders()->get()->contains(fn (SellerOrder $sellerOrder) => $sellerOrder->status->isDropped());

        $this->toBuyer($order, __('Order :number confirmed', ['number' => $order->number]), __('Order :number is confirmed.:partial Pay :total in the :app app by :deadline.', [
            'number' => $order->number,
            'partial' => $partial ? ' '.__('Some items were not available.') : '',
            'total' => Money::display($order->total_minor, $order->currency),
            'app' => config('app.name'),
            'deadline' => $this->time($order->payment_due_at),
        ]));
    }

    public function orderCancelled(Order $order): void
    {
        $this->toBuyer($order, __('Order :number cancelled', ['number' => $order->number]), __('Order :number was cancelled because :reason', [
            'number' => $order->number,
            'reason' => $order->cancellation_reason->message(),
        ]));
    }

    public function orderPaid(Order $order, Payment $payment): void
    {
        $this->toBuyer($order, __('Payment received'), __('Payment of :amount received for order :number. Thank you!', [
            'amount' => Money::display($payment->amount_minor, $payment->currency),
            'number' => $order->number,
        ]));

        foreach ($order->sellerOrders()->with('farm.members')->get() as $sellerOrder) {
            if ($sellerOrder->isPaid()) {
                $this->toFarm($sellerOrder, __('Order :number paid', ['number' => $sellerOrder->number]), __('Order :number is paid. Prepare it for :method.', [
                    'number' => $sellerOrder->number,
                    'method' => $sellerOrder->isPickup() ? __('collection') : __('delivery'),
                ]));
            }
        }
    }

    public function sellerOrderReady(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing(['order', 'farm']);

        $this->toBuyer($sellerOrder->order, __('Order :number ready', ['number' => $sellerOrder->number]), $sellerOrder->isPickup()
            ? __(':farm has your order :number ready for collection. Show code :code when you collect it.', [
                'farm' => $sellerOrder->farm->name,
                'number' => $sellerOrder->number,
                'code' => $sellerOrder->handover_code,
            ])
            : __(':farm has packed your order :number and will deliver it soon.', [
                'farm' => $sellerOrder->farm->name,
                'number' => $sellerOrder->number,
            ]), $sellerOrder);
    }

    public function sellerOrderDispatched(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing(['order', 'farm']);

        $this->toBuyer($sellerOrder->order, __('Order :number on its way', ['number' => $sellerOrder->number]), __('Your order :number from :farm is on its way. Give code :code to the person delivering it.', [
            'number' => $sellerOrder->number,
            'farm' => $sellerOrder->farm->name,
            'code' => $sellerOrder->handover_code,
        ]), $sellerOrder);
    }

    private function toBuyer(Order $order, string $title, string $message, ?SellerOrder $sellerOrder = null): void
    {
        $order->loadMissing('buyer');

        $order->buyer->notify(new MarketplaceAlert(NotificationCategory::Orders, $title, $message, [
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder?->id,
        ], sms: true));
    }

    private function toFarm(SellerOrder $sellerOrder, string $title, string $message): void
    {
        $this->notifyMembers($sellerOrder->farm, new MarketplaceAlert(NotificationCategory::Orders, $title, $message, [
            'farm_id' => $sellerOrder->farm_id,
            'seller_order_id' => $sellerOrder->id,
        ], sms: true));
    }

    private function notifyMembers(Farm $farm, MarketplaceAlert $alert): void
    {
        foreach ($farm->members as $member) {
            $member->notify($alert);
        }
    }

    private function time(CarbonInterface $at): string
    {
        return $at->copy()->timezone(Market::timezone())->format('H:i, j M');
    }
}
