<?php

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\RespondToReviewRequest;
use App\Http\Requests\Reviews\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Farm;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerOrder;
use App\Services\Reviews\ReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    public function __construct(private ReviewService $reviews) {}

    /**
     * The buyer reviews a completed farm order; it counts for the farm and every listing in it.
     */
    public function store(StoreReviewRequest $request, SellerOrder $sellerOrder): JsonResponse
    {
        $review = $this->reviews->create(
            $sellerOrder,
            $request->user(),
            (int) $request->validated('rating'),
            $request->validated('comment'),
        );

        return (new ReviewResource($review->load(['buyer', 'farm', 'products'])))->response()->setStatusCode(201);
    }

    /**
     * Public. meta.summary: average, count and how many reviews gave each star rating.
     */
    public function forFarm(Farm $farm): AnonymousResourceCollection
    {
        Gate::authorize('view', $farm);

        return $this->listing(Review::visible()->where('farm_id', $farm->id));
    }

    public function forProduct(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('view', $product);

        return $this->listing(Review::visible()->whereHas('products', fn (Builder $query) => $query->whereKey($product->id)));
    }

    public function respond(RespondToReviewRequest $request, Review $review): ReviewResource
    {
        $review = $this->reviews->respond($review, $request->validated('response'));

        return new ReviewResource($review->load(['buyer', 'farm', 'products']));
    }

    private function listing(Builder $reviews): AnonymousResourceCollection
    {
        $stars = (clone $reviews)->toBase()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $stars->sum();

        $page = $reviews->with(['buyer', 'farm', 'products'])->latest()->orderByDesc('id')->paginate(20);

        return ReviewResource::collection($page)->additional(['meta' => ['summary' => [
            'average' => $count > 0 ? number_format($stars->map(fn ($total, $rating) => $total * $rating)->sum() / $count, 2, '.', '') : null,
            'count' => $count,
            'stars' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn (int $rating) => [$rating => (int) ($stars[$rating] ?? 0)]),
        ]]]);
    }
}
