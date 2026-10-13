<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\ProductStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Support\Market;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * A farm's sales report for a period of local (market) days. Sales count from the day the order was
 * paid; answering figures count every order received in the period.
 */
class FarmReport
{
    private const TOP_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function build(Farm $farm, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = Market::timezone();
        // Local midnight to local midnight, compared in UTC as stored.
        $start = $from->startOfDay()->utc();
        $end = $to->addDay()->startOfDay()->utc();

        $paid = fn (): Builder => SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('seller_orders.farm_id', $farm->id)
            ->whereIn('seller_orders.status', SellerOrderStatus::paid())
            ->where('orders.paid_at', '>=', $start)
            ->where('orders.paid_at', '<', $end);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone],
            'currency' => Market::currency(),
            'sales' => $this->sales($paid),
            'orders' => $this->answering($farm, $start, $end),
            'daily' => $this->daily($paid, $from, $to, $timezone),
            'top_products' => $this->topProducts($paid),
            'categories' => $this->categories($paid),
            'stock' => $this->stock($farm, $paid),
            'rating' => ['average' => $farm->rating_average, 'count' => (int) $farm->rating_count],
        ];
    }

    /**
     * @param  callable(): Builder  $paid
     * @return array<string, int|string>
     */
    private function sales(callable $paid): array
    {
        $totals = $paid()->toBase()
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(seller_orders.total_minor), 0) as gross')
            ->selectRaw('coalesce(sum(seller_orders.subtotal_minor), 0) as goods')
            ->selectRaw('coalesce(sum(seller_orders.delivery_fee_minor), 0) as delivery')
            ->first();

        $ledger = LedgerEntry::whereIn('seller_order_id', $paid()->select('seller_orders.id'))
            ->groupBy('type')
            ->selectRaw('type, sum(amount_minor) as total')
            ->toBase()
            ->pluck('total', 'type');

        return [
            'orders' => (int) $totals->orders,
            'gross' => Money::format((int) $totals->gross),
            'goods' => Money::format((int) $totals->goods),
            'delivery_fees' => Money::format((int) $totals->delivery),
            'platform_fees' => Money::format(-(int) ($ledger[LedgerEntryType::Commission->value] ?? 0)),
            'refunds' => Money::format(-(int) ($ledger[LedgerEntryType::Refund->value] ?? 0)),
            // What the farm keeps: sales less fees and refunds (pending or available).
            'net' => Money::format((int) $ledger->sum()),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function answering(Farm $farm, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = SellerOrder::where('farm_id', $farm->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->toBase()
            ->selectRaw('count(*) as received')
            ->selectRaw('count(accepted_at) as accepted')
            ->selectRaw('count(rejected_at) as rejected')
            ->selectRaw('count(*) filter (where status = ?) as missed', [SellerOrderStatus::Expired->value])
            ->first();

        $answered = $counts->accepted + $counts->rejected + $counts->missed;

        return [
            'received' => (int) $counts->received,
            'accepted' => (int) $counts->accepted,
            'rejected' => (int) $counts->rejected,
            'missed' => (int) $counts->missed,
            // Share of orders the farm said yes to, out of those it answered or let expire.
            'acceptance_rate' => $answered > 0 ? number_format($counts->accepted * 100 / $answered, 1, '.', '') : null,
        ];
    }

    /**
     * Every day in the period, including days without sales.
     *
     * @param  callable(): Builder  $paid
     * @return list<array{date: string, orders: int, gross: string}>
     */
    private function daily(callable $paid, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $byDay = $paid()->toBase()
            ->selectRaw("to_char((orders.paid_at at time zone 'UTC') at time zone ?, 'YYYY-MM-DD') as day", [$timezone])
            ->selectRaw('count(*) as orders, sum(seller_orders.total_minor) as gross')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $row = $byDay->get($date = $day->toDateString());
            $days[] = ['date' => $date, 'orders' => (int) ($row->orders ?? 0), 'gross' => Money::format((int) ($row->gross ?? 0))];
        }

        return $days;
    }

    /**
     * Best sellers by revenue, per product and selling unit.
     *
     * @param  callable(): Builder  $paid
     * @return list<array<string, string>>
     */
    private function topProducts(callable $paid): array
    {
        return OrderItem::query()
            ->whereIn('seller_order_id', $paid()->select('seller_orders.id'))
            ->groupBy('product_id', 'unit_code', 'unit_plural_name')
            ->selectRaw('product_id, max(product_title) as title, unit_code, unit_plural_name')
            ->selectRaw('sum(coalesce(confirmed_quantity, quantity)) as quantity, sum(line_total_minor) as revenue')
            ->orderByDesc('revenue')
            ->limit(self::TOP_LIMIT)
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'title' => $row->title,
                'quantity' => Quantity::format((string) $row->quantity),
                'unit' => $row->unit_plural_name,
                'revenue' => Money::format((int) $row->revenue),
            ])
            ->all();
    }

    /**
     * @param  callable(): Builder  $paid
     * @return list<array<string, string>>
     */
    private function categories(callable $paid): array
    {
        return OrderItem::query()
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('order_items.seller_order_id', $paid()->select('seller_orders.id'))
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id, categories.name, sum(order_items.line_total_minor) as revenue')
            ->orderByDesc('revenue')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['category_id' => $row->category_id, 'name' => $row->name, 'revenue' => Money::format((int) $row->revenue)])
            ->all();
    }

    /**
     * Live listings, the ones running low, and stock that did not sell in the period.
     *
     * @param  callable(): Builder  $paid
     * @return array<string, mixed>
     */
    private function stock(Farm $farm, callable $paid): array
    {
        $live = fn (): Builder => Product::where('farm_id', $farm->id)
            ->where('status', ProductStatus::Active)
            ->where('expires_at', '>', now());

        $unsold = $live()
            ->whereColumn('stock_on_hand', '>', 'stock_reserved')
            ->whereNotIn('id', OrderItem::whereIn('seller_order_id', $paid()->select('seller_orders.id'))->select('product_id'))
            ->with('stockUnit')
            ->orderByRaw('stock_on_hand - stock_reserved desc')
            ->get();

        return [
            'live_listings' => $live()->count(),
            'low_stock' => $live()->whereNotNull('low_stock_threshold')->whereRaw('stock_on_hand - stock_reserved <= low_stock_threshold')->count(),
            'unsold_count' => $unsold->count(),
            'unsold' => $unsold->take(self::TOP_LIMIT)->map(fn (Product $product) => [
                'product_id' => $product->id,
                'title' => $product->title,
                'available' => Quantity::format($product->availableStock()),
                'unit' => $product->stockUnit->plural_name,
            ])->values()->all(),
        ];
    }
}
