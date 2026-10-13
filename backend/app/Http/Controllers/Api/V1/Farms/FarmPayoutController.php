<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\Farm;
use App\Services\Settlement\PayoutService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The owner's view of where the farm is paid, and what it has been paid.
 */
class FarmPayoutController extends Controller
{
    /**
     * {"data": null} until an account is set up.
     */
    public function showAccount(Farm $farm): JsonResponse
    {
        Gate::authorize('managePayouts', $farm);

        $account = $farm->payoutAccount;

        return response()->json(['data' => $account ? new PayoutAccountResource($account) : null]);
    }

    /**
     * Body: {"provider": "EcoCash", "account_name": "...", "account_number": "0771234567"}. Every owner is
     * told by SMS, and payouts pause for a while after a change.
     */
    public function updateAccount(Request $request, Farm $farm, PayoutService $payouts): PayoutAccountResource
    {
        Gate::authorize('managePayouts', $farm);

        $request->merge(['account_number' => Phone::normalize($request->input('account_number'))]);
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:'.Phone::PATTERN],
        ], [
            'account_number.regex' => __('Enter the mobile money number, e.g. 0771234567.'),
        ]);

        return new PayoutAccountResource($payouts->saveAccount(
            $farm,
            $request->user(),
            $validated['provider'],
            $validated['account_name'],
            $validated['account_number'],
        ));
    }

    public function index(Farm $farm): AnonymousResourceCollection
    {
        Gate::authorize('viewEarnings', $farm);

        return PayoutResource::collection($farm->payouts()->latest('paid_at')->orderByDesc('id')->paginate(20));
    }
}
