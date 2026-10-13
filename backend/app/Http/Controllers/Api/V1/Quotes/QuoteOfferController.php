<?php

namespace App\Http\Controllers\Api\V1\Quotes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\MakeQuoteOfferRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuoteOfferResource;
use App\Models\Farm;
use App\Models\QuoteOffer;
use App\Models\QuoteRequest;
use App\Models\UserAddress;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Farms send and withdraw offers; buyers accept or decline them, and order again on accepted ones.
 */
class QuoteOfferController extends Controller
{
    public function __construct(private QuoteService $quotes) {}

    /**
     * A new offer, or a change to the farm's pending one.
     */
    public function store(MakeQuoteOfferRequest $request, QuoteRequest $quoteRequest): JsonResponse
    {
        $farm = Farm::findOrFail($request->validated('farm_id'));
        Gate::authorize('update', $farm);

        $offer = $this->quotes->offer($quoteRequest, $farm, $request->user(), $request->validated());

        return (new QuoteOfferResource($offer->load(['farm', 'variant.product', 'variant.unit', 'quoteRequest'])))
            ->response()
            ->setStatusCode($offer->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(QuoteOffer $quoteOffer): QuoteOfferResource
    {
        Gate::authorize('update', $quoteOffer->farm);

        return $this->resource($this->quotes->withdraw($quoteOffer));
    }

    public function decline(Request $request, QuoteOffer $quoteOffer): QuoteOfferResource
    {
        $this->ensureBuyer($request, $quoteOffer);

        return $this->resource($this->quotes->decline($quoteOffer));
    }

    /**
     * Body: {"address_id"?: needed for delivery, "note"?}. Places the first order for the farm to confirm.
     */
    public function accept(Request $request, QuoteOffer $quoteOffer): JsonResponse
    {
        $this->ensureBuyer($request, $quoteOffer);
        [$address, $note] = $this->orderDetails($request);

        $order = $this->quotes->accept($quoteOffer, $request->user(), $address, $note);

        return (new OrderResource($order->load(OrderResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Another order at the agreed price, for requests that repeat.
     */
    public function order(Request $request, QuoteOffer $quoteOffer): JsonResponse
    {
        $this->ensureBuyer($request, $quoteOffer);
        [$address, $note] = $this->orderDetails($request);

        $order = $this->quotes->orderAgain($quoteOffer, $request->user(), $address, $note);

        return (new OrderResource($order->load(OrderResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * @return array{0: ?UserAddress, 1: ?string}
     */
    private function orderDetails(Request $request): array
    {
        $validated = $request->validate([
            'address_id' => ['nullable', 'uuid', Rule::exists('user_addresses', 'id')->where('user_id', $request->user()->id)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return [isset($validated['address_id']) ? UserAddress::find($validated['address_id']) : null, $validated['note'] ?? null];
    }

    private function ensureBuyer(Request $request, QuoteOffer $quoteOffer): void
    {
        abort_unless($quoteOffer->quoteRequest()->value('buyer_id') === $request->user()->id, 404);
    }

    private function resource(QuoteOffer $offer): QuoteOfferResource
    {
        return new QuoteOfferResource($offer->load(['farm', 'variant.product', 'variant.unit', 'quoteRequest']));
    }
}
