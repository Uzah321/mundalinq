<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Rules\DecimalString;
use App\Services\Settlement\PayoutService;
use App\Support\Market;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paying farms: send the money through the payout provider, then record it here.
 */
class PayoutController extends Controller
{
    /**
     * Farms with money available to pay out, largest balance first.
     */
    public function due(): JsonResponse
    {
        $balances = LedgerEntry::whereNotNull('available_at')
            ->groupBy('farm_id')
            ->havingRaw('sum(amount_minor) > 0')
            ->selectRaw('farm_id, sum(amount_minor) as available_minor')
            ->orderByDesc('available_minor')
            ->toBase()
            ->get();

        $farms = Farm::withTrashed()->with('payoutAccount')->findMany($balances->pluck('farm_id'))->keyBy('id');

        return response()->json(['data' => $balances->map(fn ($balance) => [
            'farm' => ['id' => $balance->farm_id, 'name' => $farms[$balance->farm_id]->name],
            'available' => Money::format((int) $balance->available_minor),
            'currency' => Market::currency(),
            'payout_account' => ($account = $farms[$balance->farm_id]->payoutAccount) ? new PayoutAccountResource($account) : null,
        ])]);
    }

    /**
     * Newest first; filter by farm_id.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['farm_id' => ['nullable', 'uuid']]);

        $payouts = Payout::query()
            ->when($filters['farm_id'] ?? null, fn ($query, string $farmId) => $query->where('farm_id', $farmId))
            ->with('farm')
            ->latest('paid_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return PayoutResource::collection($payouts);
    }

    /**
     * Body: {"amount": "120.00", "reference": "the transfer reference", "note": "..."}.
     */
    public function store(Request $request, Farm $farm, PayoutService $payouts): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', DecimalString::money()],
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $payout = $payouts->record($farm, $request->user(), Money::toMinor($validated['amount']), $validated['reference'], $validated['note'] ?? null);

        return (new PayoutResource($payout->load('farm')))->response()->setStatusCode(201);
    }
}
