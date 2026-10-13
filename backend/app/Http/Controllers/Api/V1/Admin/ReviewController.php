<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Moderation: hiding takes a review out of public listings and ratings without deleting it.
 */
class ReviewController extends Controller
{
    public function __construct(private ReviewService $reviews) {}

    /**
     * Newest first. Filters: hidden (true/false), farm_id, max_rating (e.g. 2 for complaints).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'hidden' => ['nullable', 'boolean'],
            'farm_id' => ['nullable', 'uuid'],
            'max_rating' => ['nullable', 'integer', 'between:1,5'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $reviews = Review::query()
            ->when($request->has('hidden'), fn ($query) => $request->boolean('hidden') ? $query->whereNotNull('hidden_at') : $query->whereNull('hidden_at'))
            ->when($filters['farm_id'] ?? null, fn ($query, string $farmId) => $query->where('farm_id', $farmId))
            ->when($filters['max_rating'] ?? null, fn ($query, int $rating) => $query->where('rating', '<=', $rating))
            ->with(['buyer', 'farm', 'products'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }

    public function hide(Request $request, Review $review): ReviewResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return new ReviewResource($this->reviews->hide($review, $request->user(), $validated['reason'])->load(['buyer', 'farm', 'products']));
    }

    public function unhide(Request $request, Review $review): ReviewResource
    {
        return new ReviewResource($this->reviews->unhide($review, $request->user())->load(['buyer', 'farm', 'products']));
    }
}
