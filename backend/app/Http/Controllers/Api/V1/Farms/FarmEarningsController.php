<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Services\Settlement\SettlementService;
use App\Support\Market;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FarmEarningsController extends Controller
{
    /**
     * Pending: paid orders not yet final. Available: final sales, ready to be paid out.
     */
    public function show(Farm $farm, SettlementService $settlement): JsonResponse
    {
        Gate::authorize('viewEarnings', $farm);

        $balances = $settlement->balances($farm);
        $entries = $farm->ledgerEntries()->with('sellerOrder:id,number')->latest()->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'currency' => Market::currency(),
            'pending' => Money::format($balances['pending_minor']),
            'available' => Money::format($balances['available_minor']),
            'entries' => $entries->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'type' => $entry->type,
                'amount' => Money::format($entry->amount_minor),
                'description' => $entry->description,
                'seller_order_number' => $entry->sellerOrder?->number,
                'available' => $entry->available_at !== null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]),
        ]]);
    }
}
