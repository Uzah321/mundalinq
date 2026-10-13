<?php

namespace App\Http\Controllers\Api\V1\Quotes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\StoreQuoteRequestRequest;
use App\Http\Resources\QuoteRequestResource;
use App\Models\QuoteRequest;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The buyer's requests for quotes and the offers on them.
 */
class QuoteRequestController extends Controller
{
    /** What an offer shows: the farm, the listing and its unit, and the request (for repeat orders). */
    public const OFFER_RELATIONS = ['offers.farm', 'offers.variant.product', 'offers.variant.unit', 'offers.quoteRequest'];

    public function __construct(private QuoteService $quotes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = QuoteRequest::where('buyer_id', $request->user()->id)
            ->with(['category', 'unit'])
            ->withCount('offers')
            ->latest()
            ->paginate(20);

        return QuoteRequestResource::collection($requests);
    }

    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        [$quote, $farmsTold] = $this->quotes->request($request->user(), $request->details(), $request->openDays());

        return (new QuoteRequestResource($quote->load(['category', 'unit', 'buyer'])->loadCount('offers')))
            ->additional(['meta' => ['farms_told' => $farmsTold]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Offers cheapest first, by total.
     */
    public function show(Request $request, QuoteRequest $quoteRequest): QuoteRequestResource
    {
        $this->ensureBuyerOrStaff($request, $quoteRequest);
        $quoteRequest->load(['category', 'unit', 'buyer', ...self::OFFER_RELATIONS]);
        $quoteRequest->setRelation('offers', $quoteRequest->offers->sortBy(fn ($offer) => $offer->totalMinor())->values());

        return new QuoteRequestResource($quoteRequest);
    }

    public function close(Request $request, QuoteRequest $quoteRequest): QuoteRequestResource
    {
        abort_unless($quoteRequest->buyer_id === $request->user()->id, 404);

        return new QuoteRequestResource($this->quotes->close($quoteRequest)->load(['category', 'unit', 'buyer', ...self::OFFER_RELATIONS]));
    }

    private function ensureBuyerOrStaff(Request $request, QuoteRequest $quoteRequest): void
    {
        abort_unless($quoteRequest->buyer_id === $request->user()->id || $request->user()->isStaff(), 404);
    }
}
