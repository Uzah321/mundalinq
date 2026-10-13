<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\MarketplaceDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Pilot KPIs over the last `days` (default 30) and the size of each work queue.
     */
    public function show(Request $request, MarketplaceDashboard $dashboard): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'between:1,366']]);

        return response()->json(['data' => $dashboard->build((int) ($validated['days'] ?? 30))]);
    }
}
