<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Services\Reports\FarmReport;
use App\Support\Market;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FarmAnalyticsController extends Controller
{
    /**
     * Sales report for local dates from..to (both included); defaults to the last 30 days. Owners only,
     * like earnings.
     */
    public function show(Request $request, Farm $farm, FarmReport $report): JsonResponse
    {
        Gate::authorize('viewEarnings', $farm);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = Market::timezone();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $timezone) : CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $timezone) : $to->subDays(29);

        abort_if($from->diffInDays($to) > 366, 422, __('Choose a period of at most a year.'));

        return response()->json(['data' => $report->build($farm, $from, $to)]);
    }
}
