<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\AccountingReport;
use App\Support\Market;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounting reports. Add format=csv to download a file Excel opens directly.
 */
class ReportController extends Controller
{
    public function __construct(private AccountingReport $report) {}

    /**
     * Month by month for a year: orders, sales, MundaLinq's income, refunds and payouts.
     */
    public function monthly(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $rows = $this->report->months((int) $validated['year']);

        if (($validated['format'] ?? 'json') === 'csv') {
            return $this->csv("mundalinq-monthly-{$validated['year']}.csv", [
                'Month', 'Orders placed', 'Orders completed', 'Sales', 'Platform fees', 'Featuring', 'MundaLinq income', 'Refunds to buyers', 'Payouts to farms',
            ], $rows);
        }

        return response()->json(['data' => $rows, 'meta' => ['currency' => Market::currency()]]);
    }

    /**
     * Each farm's sales, fees, refunds, featuring and payouts in one month.
     */
    public function farms(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $rows = $this->report->farms($validated['month']);

        if (($validated['format'] ?? 'json') === 'csv') {
            return $this->csv("mundalinq-farms-{$validated['month']}.csv", [
                'Farm id', 'Farm', 'Sales', 'Platform fees', 'Refunds', 'Featuring', 'Payouts', 'Earned (after fees, refunds and featuring)',
            ], $rows);
        }

        return response()->json(['data' => $rows, 'meta' => ['currency' => Market::currency()]]);
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<string, int|string>>  $rows
     */
    private function csv(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            // A byte-order mark makes Excel read the file as UTF-8 (farm names with accents stay intact).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_values($row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
