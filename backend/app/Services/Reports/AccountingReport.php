<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\RefundStatus;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Market;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money by month for accounting, in the market's time zone. Farm money comes from the settlement
 * ledger (sales, platform fees, refunds they bore, featuring, payouts); refunds to buyers from the
 * refunds themselves, which include payments returned for orders that could not take them.
 */
class AccountingReport
{
    /**
     * One row per month of the year.
     *
     * @return list<array{month: string, orders_placed: int, orders_completed: int, sales: string, platform_fees: string, featuring: string, income: string, refunds: string, payouts: string}>
     */
    public function months(int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, Market::timezone());
        $to = $from->addYear();

        $ledger = $this->perMonth(LedgerEntry::query(), 'created_at', $from, $to, 'type', 'sum(amount_minor)');
        $placed = $this->perMonth(Order::query(), 'created_at', $from, $to, null, 'count(*)');
        $completed = $this->perMonth(Order::query(), 'completed_at', $from, $to, null, 'count(*)');
        $refunds = $this->perMonth(Refund::where('status', RefundStatus::Succeeded), 'completed_at', $from, $to, null, 'sum(amount_minor)');

        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $of = fn (LedgerEntryType $type) => (int) ($ledger[$month][$type->value] ?? 0);
            $fees = -$of(LedgerEntryType::Commission);
            $featuring = -$of(LedgerEntryType::Promotion);

            $rows[] = [
                'month' => sprintf('%04d-%02d', $year, $month),
                'orders_placed' => (int) ($placed[$month][''] ?? 0),
                'orders_completed' => (int) ($completed[$month][''] ?? 0),
                'sales' => Money::format($of(LedgerEntryType::Sale)),
                'platform_fees' => Money::format($fees),
                'featuring' => Money::format($featuring),
                'income' => Money::format($fees + $featuring),
                'refunds' => Money::format((int) ($refunds[$month][''] ?? 0)),
                'payouts' => Money::format(-$of(LedgerEntryType::Payout)),
            ];
        }

        return $rows;
    }

    /**
     * Each farm's money in one month ("2026-10"), largest sales first.
     *
     * @return list<array{farm_id: string, farm: string, sales: string, platform_fees: string, refunds: string, featuring: string, payouts: string, net: string}>
     */
    public function farms(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', Market::timezone());
        $to = $from->addMonth();

        $totals = LedgerEntry::query()
            ->whereBetween('created_at', [$from->utc(), $to->utc()->subMicrosecond()])
            ->selectRaw('farm_id, type, sum(amount_minor) as total')
            ->groupBy('farm_id', 'type')
            ->toBase()
            ->get()
            ->groupBy('farm_id');

        $names = Farm::withTrashed()->findMany($totals->keys())->pluck('name', 'id');

        return $totals->map(function ($entries, string $farmId) use ($names) {
            $of = fn (LedgerEntryType $type) => (int) ($entries->firstWhere('type', $type->value)->total ?? 0);

            return [
                'farm_id' => $farmId,
                'farm' => $names[$farmId] ?? '',
                'sales' => $of(LedgerEntryType::Sale),
                'platform_fees' => -$of(LedgerEntryType::Commission),
                'refunds' => -$of(LedgerEntryType::Refund),
                'featuring' => -$of(LedgerEntryType::Promotion),
                'payouts' => -$of(LedgerEntryType::Payout),
                'net' => (int) $entries->sum('total') - $of(LedgerEntryType::Payout),
            ];
        })
            ->sortByDesc('sales')
            ->values()
            ->map(fn (array $row) => [
                'farm_id' => $row['farm_id'],
                'farm' => $row['farm'],
                ...array_map(fn (int $minor) => Money::format($minor), array_diff_key($row, ['farm_id' => 0, 'farm' => 0])),
            ])
            ->all();
    }

    /**
     * Totals grouped by month number (in the market's time zone) and, optionally, another column.
     *
     * @return array<int, array<string, int|string>>
     */
    private function perMonth(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $to, ?string $by, string $aggregate): array
    {
        $month = "extract(month from ({$column} at time zone 'UTC') at time zone ?)::int";

        $rows = $query
            ->whereBetween($column, [$from->utc(), $to->utc()->subMicrosecond()])
            ->selectRaw("{$month} as month".($by ? ", {$by} as key" : ", '' as key").", {$aggregate} as total", [Market::timezone()])
            ->groupByRaw('1, 2')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->month][(string) $row->key] = $row->total;
        }

        return $totals;
    }
}
