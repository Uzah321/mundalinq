<?php

namespace App\Services\Reports;

use App\Enums\DisputeStatus;
use App\Enums\FarmVerificationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Models\Dispute;
use App\Models\Farm;
use App\Models\FarmVerification;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Support\Market;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pilot KPIs (spec section 18) over the last N days, plus the size of every staff work queue.
 */
class MarketplaceDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $days): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $within = fn (string $column) => fn (Builder $query) => $query->where($column, '>=', $from)->where($column, '<=', $to);

        $placed = Order::where($within('created_at'));
        $finished = (clone $placed)->whereIn('status', [OrderStatus::Completed, OrderStatus::Cancelled]);
        $finishedCount = $finished->count();
        $completedCount = (clone $finished)->where('status', OrderStatus::Completed)->count();

        $paid = Order::where($within('paid_at'));
        $activeBuyerIds = (clone $paid)->distinct()->pluck('buyer_id');
        $repeatBuyers = $activeBuyerIds->isEmpty() ? 0 : Order::whereIn('buyer_id', $activeBuyerIds)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $to)
            ->groupBy('buyer_id')
            ->havingRaw('count(*) > 1')
            ->pluck('buyer_id')
            ->count();

        $fees = -(int) LedgerEntry::where('type', LedgerEntryType::Commission)->where($within('created_at'))->sum('amount_minor');

        return [
            'period' => ['days' => $days, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'currency' => Market::currency(),
            // Value of goods and delivery paid for in the period.
            'gmv' => Money::format((int) (clone $paid)->sum('total_minor')),
            'orders' => [
                'placed' => (clone $placed)->count(),
                'paid' => (clone $paid)->count(),
                'completed' => Order::where($within('completed_at'))->count(),
                'cancelled' => Order::where($within('cancelled_at'))->count(),
                // Of the orders placed in the period that have finished either way.
                'fulfilment_rate' => $this->percent($completedCount, $finishedCount),
                'cancellation_rate' => $this->percent($finishedCount - $completedCount, $finishedCount),
            ],
            'revenue' => [
                // Platform fees earned, net of fees returned with refunds.
                'platform_fees' => Money::format($fees),
                'refunds' => Money::format((int) Refund::where('status', RefundStatus::Succeeded)->where($within('completed_at'))->sum('amount_minor')),
            ],
            'buyers' => [
                'active' => $activeBuyerIds->count(),
                'repeat_rate' => $this->percent($repeatBuyers, $activeBuyerIds->count()),
                'new_accounts' => User::where($within('created_at'))->count(),
            ],
            'sellers' => [
                // Verified farms with something buyers can order right now.
                'active_verified' => Farm::approved()->whereHas('products', fn (Builder $product) => $product
                    ->where('status', ProductStatus::Active)
                    ->where('expires_at', '>', now())
                    ->whereColumn('stock_on_hand', '>', 'stock_reserved'))->count(),
                'new_farms' => Farm::where($within('created_at'))->count(),
            ],
            'queues' => [
                'farm_verifications' => FarmVerification::where('status', FarmVerificationStatus::Pending)->count(),
                'listings_to_review' => Product::where('status', ProductStatus::PendingReview)->count(),
                'open_disputes' => Dispute::where('status', DisputeStatus::Open)->count(),
                'payments_to_refund' => Payment::where('requires_refund', true)->count(),
                'failed_refunds' => Refund::where('status', RefundStatus::Failed)->count(),
                'farms_awaiting_payout' => LedgerEntry::whereNotNull('available_at')
                    ->groupBy('farm_id')
                    ->havingRaw('sum(amount_minor) > 0')
                    ->pluck('farm_id')
                    ->count(),
            ],
        ];
    }

    private function percent(int $part, int $whole): ?string
    {
        return $whole > 0 ? number_format($part * 100 / $whole, 1, '.', '') : null;
    }
}
