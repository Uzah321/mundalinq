<?php

namespace App\Services\Reviews;

use App\Enums\NotificationCategory;
use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Farm;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Verified-purchase reviews. Every change recalculates the ratings stored on the farm and its
 * listings, so only visible reviews ever count.
 */
class ReviewService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(SellerOrder $sellerOrder, User $buyer, int $rating, ?string $comment): Review
    {
        $review = DB::transaction(function () use ($sellerOrder, $buyer, $rating, $comment) {
            $sellerOrder = SellerOrder::whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();

            if ($sellerOrder->status !== SellerOrderStatus::Completed) {
                throw new BusinessRuleException(__('You can review an order once it is complete.'));
            }

            if ($sellerOrder->completed_at->isBefore(now()->subDays(config('mundalinq.reviews.window_days')))) {
                throw new BusinessRuleException(__('The time to review this order has passed.'));
            }

            if ($sellerOrder->review()->exists()) {
                throw new BusinessRuleException(__('You have already reviewed this order.'));
            }

            $review = Review::forceCreate([
                'seller_order_id' => $sellerOrder->id,
                'farm_id' => $sellerOrder->farm_id,
                'buyer_id' => $buyer->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
            $review->products()->attach($sellerOrder->items()->pluck('product_id')->unique()->all());

            $this->refreshRatings($review);

            return $review;
        });

        $review->load('farm.members');

        foreach ($review->farm->members as $member) {
            $member->notify(new MarketplaceAlert(
                NotificationCategory::Reviews,
                __('New :rating-star review', ['rating' => $rating]),
                $comment ?? __('A buyer rated order :number :rating out of 5.', ['number' => $sellerOrder->number, 'rating' => $rating]),
                ['farm_id' => $review->farm_id, 'review_id' => $review->id],
            ));
        }

        return $review;
    }

    /**
     * The farm's public reply. One per review.
     */
    public function respond(Review $review, string $response): Review
    {
        $review = DB::transaction(function () use ($review, $response) {
            $review = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($review->farm_response !== null) {
                throw new BusinessRuleException(__('The farm has already replied to this review.'));
            }

            $review->forceFill(['farm_response' => $response, 'farm_responded_at' => now()])->save();

            return $review;
        });

        $review->load(['buyer', 'farm']);
        $review->buyer->notify(new MarketplaceAlert(
            NotificationCategory::Reviews,
            __(':farm replied to your review', ['farm' => $review->farm->name]),
            $response,
            ['review_id' => $review->id, 'farm_id' => $review->farm_id],
        ));

        return $review;
    }

    public function hide(Review $review, User $moderator, string $reason): Review
    {
        return DB::transaction(function () use ($review, $moderator, $reason) {
            $review = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($review->isHidden()) {
                throw new BusinessRuleException(__('This review is already hidden.'));
            }

            $review->forceFill(['hidden_at' => now(), 'hidden_reason' => $reason, 'hidden_by' => $moderator->id])->save();
            $this->refreshRatings($review);
            $this->audit->record($moderator, 'review.hidden', $review, ['farm_id' => $review->farm_id, 'reason' => $reason]);

            return $review;
        });
    }

    public function unhide(Review $review, User $moderator): Review
    {
        return DB::transaction(function () use ($review, $moderator) {
            $review = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if (! $review->isHidden()) {
                throw new BusinessRuleException(__('This review is not hidden.'));
            }

            $review->forceFill(['hidden_at' => null, 'hidden_reason' => null, 'hidden_by' => null])->save();
            $this->refreshRatings($review);
            $this->audit->record($moderator, 'review.unhidden', $review, ['farm_id' => $review->farm_id]);

            return $review;
        });
    }

    /**
     * Recount from scratch, with the farm locked so concurrent reviews cannot overwrite each other's totals.
     */
    private function refreshRatings(Review $review): void
    {
        Farm::withTrashed()->whereKey($review->farm_id)->lockForUpdate()->first();

        Farm::withTrashed()->whereKey($review->farm_id)->update(
            $this->ratingColumns(Review::visible()->where('farm_id', $review->farm_id)),
        );

        foreach ($review->products()->pluck('products.id') as $productId) {
            Product::withTrashed()->whereKey($productId)->update(
                $this->ratingColumns(Review::visible()->whereHas('products', fn (Builder $product) => $product->whereKey($productId))),
            );
        }
    }

    /**
     * @return array{rating_count: int, rating_average: ?string}
     */
    private function ratingColumns(Builder $reviews): array
    {
        $stats = $reviews->toBase()->selectRaw('count(*) as review_count, round(avg(rating), 2) as average')->first();

        return [
            'rating_count' => (int) $stats->review_count,
            'rating_average' => $stats->review_count > 0 ? (string) $stats->average : null,
        ];
    }
}
