<?php

namespace App\Services\Settlement;

use App\Enums\LedgerEntryType;
use App\Enums\SellerOrderStatus;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SellerOrder;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Farm earnings: recorded as pending when an order is paid, available once the sale is final.
 * Payouts are recorded by administrators after they send the money (PayoutService).
 */
class SettlementService
{
    /**
     * The order is paid: record each farm's sale and the platform fee on its goods (not on delivery).
     */
    public function recordSale(Order $order, Payment $payment): void
    {
        $rate = (string) config('mundalinq.commission.rate');

        foreach ($order->sellerOrders()->where('status', SellerOrderStatus::Preparing)->get() as $sellerOrder) {
            $this->entry($sellerOrder, $payment, LedgerEntryType::Sale, $sellerOrder->total_minor, __('Sale :number', ['number' => $sellerOrder->number]));

            $commission = Money::percentage($sellerOrder->subtotal_minor, $rate);

            if ($commission > 0) {
                $this->entry($sellerOrder, $payment, LedgerEntryType::Commission, -$commission, __('Platform fee for :number', ['number' => $sellerOrder->number]));
            }
        }
    }

    /**
     * Part or all of a sale goes back to the buyer. The farm bears the refund, and the platform
     * returns its fee on the goods refunded (a refund of the delivery fee carries no fee).
     */
    public function recordRefund(SellerOrder $sellerOrder, Payment $payment, int $refundMinor): void
    {
        $this->entry($sellerOrder, $payment, LedgerEntryType::Refund, -$refundMinor, __('Refund for :number', ['number' => $sellerOrder->number]));

        $commission = -(int) LedgerEntry::where('seller_order_id', $sellerOrder->id)->where('type', LedgerEntryType::Commission)->sum('amount_minor');
        $goodsRefunded = min($refundMinor, $sellerOrder->subtotal_minor);

        if ($commission <= 0 || $sellerOrder->subtotal_minor <= 0) {
            return;
        }

        $returned = BigDecimal::of($commission)
            ->multipliedBy($goodsRefunded)
            ->dividedBy($sellerOrder->subtotal_minor, 0, RoundingMode::HalfUp)
            ->toInt();

        if ($returned > 0) {
            $this->entry($sellerOrder, $payment, LedgerEntryType::Commission, $returned, __('Platform fee returned with refund for :number', ['number' => $sellerOrder->number]));
        }
    }

    /**
     * The sale is final (delivered and not disputed): its earnings can be paid out.
     */
    public function makeAvailable(SellerOrder $sellerOrder): void
    {
        LedgerEntry::where('seller_order_id', $sellerOrder->id)->whereNull('available_at')->update(['available_at' => now()]);
    }

    /**
     * Money sent to the farm leaves its available balance at once.
     */
    public function recordPayout(Payout $payout): void
    {
        LedgerEntry::forceCreate([
            'farm_id' => $payout->farm_id,
            'payout_id' => $payout->id,
            'type' => LedgerEntryType::Payout,
            'amount_minor' => -$payout->amount_minor,
            'currency' => $payout->currency,
            'description' => __('Payout :reference', ['reference' => $payout->reference]),
            'available_at' => now(),
        ]);
    }

    /**
     * @return array{pending_minor: int, available_minor: int}
     */
    public function balances(Farm $farm): array
    {
        $totals = LedgerEntry::where('farm_id', $farm->id)
            ->selectRaw('coalesce(sum(amount_minor) filter (where available_at is null), 0) as pending')
            ->selectRaw('coalesce(sum(amount_minor) filter (where available_at is not null), 0) as available')
            ->toBase()
            ->first();

        return [
            'pending_minor' => (int) $totals->pending,
            'available_minor' => (int) $totals->available,
        ];
    }

    private function entry(SellerOrder $sellerOrder, Payment $payment, LedgerEntryType $type, int $amountMinor, string $description): void
    {
        LedgerEntry::forceCreate([
            'farm_id' => $sellerOrder->farm_id,
            'seller_order_id' => $sellerOrder->id,
            'payment_id' => $payment->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => $payment->currency,
            'description' => $description,
        ]);
    }
}
