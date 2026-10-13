<?php

namespace App\Services\Catalog;

use App\Enums\FarmMemberRole;
use App\Enums\NotificationCategory;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves listings between statuses:
 *
 *   draft/rejected --publish--> pending_review --approve--> active <--pause/resume--> paused
 *                          \--(no review needed)--> active
 *   pending_review/active/paused --reject (admin)--> rejected
 */
class ProductLifecycle
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Send a listing to review, or straight live when no review applies.
     */
    public function publish(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product = $this->lock($product);

            if (! $product->status->canBePublished()) {
                throw new BusinessRuleException(__('This listing has already been published.'));
            }

            $this->ensureReadyForBuyers($product);

            if ($this->needsApproval($product)) {
                $product->forceFill(['status' => ProductStatus::PendingReview, 'rejection_reason' => null])->save();
            } else {
                $this->activate($product);
            }

            return $product;
        });
    }

    public function approve(Product $product, User $reviewer): Product
    {
        return DB::transaction(function () use ($product, $reviewer) {
            $product = $this->lock($product);

            if ($product->status !== ProductStatus::PendingReview) {
                throw new BusinessRuleException(__('This listing is not waiting for review.'));
            }

            $product->forceFill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $this->activate($product);

            $this->audit->record($reviewer, 'product.approved', $product, ['farm_id' => $product->farm_id]);

            return $product;
        });
    }

    /**
     * Refuse a listing under review, or take down a live one.
     */
    public function reject(Product $product, User $reviewer, string $reason): Product
    {
        $product = DB::transaction(function () use ($product, $reviewer, $reason) {
            $product = $this->lock($product);

            if (! $product->status->isSubmitted()) {
                throw new BusinessRuleException(__('Only listings under review or live can be rejected.'));
            }

            $action = $product->status === ProductStatus::PendingReview ? 'product.rejected' : 'product.taken_down';

            $product->forceFill([
                'status' => ProductStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record($reviewer, $action, $product, ['farm_id' => $product->farm_id, 'reason' => $reason]);

            return $product;
        });

        $this->notifyOwners($product, __('Listing not live'), __('Your listing ":title" is not live: :reason Edit it in the :app app and publish it again.', [
            'title' => $product->title,
            'reason' => Str::finish($reason, '.'),
            'app' => config('app.name'),
        ]));

        return $product;
    }

    public function pause(Product $product): Product
    {
        return $this->transition($product, ProductStatus::Active, function (Product $product) {
            $product->forceFill(['status' => ProductStatus::Paused])->save();
        }, __('Only live listings can be paused.'));
    }

    public function resume(Product $product): Product
    {
        return $this->transition($product, ProductStatus::Paused, function (Product $product) {
            $product->forceFill([
                'status' => ProductStatus::Active,
                'expires_at' => $product->isExpired() ? $this->newExpiry() : $product->expires_at,
            ])->save();
        }, __('Only paused listings can be resumed.'));
    }

    /**
     * Keep a listing visible for another full period.
     */
    public function renew(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product = $this->lock($product);

            if (! in_array($product->status, [ProductStatus::Active, ProductStatus::Paused], true)) {
                throw new BusinessRuleException(__('Only published listings can be renewed.'));
            }

            $product->forceFill(['expires_at' => $this->newExpiry()])->save();

            return $product;
        });
    }

    /**
     * Review applies to every listing when the platform says so, and always to sensitive categories.
     */
    public function needsApproval(Product $product): bool
    {
        return config('mundalinq.catalog.require_product_approval')
            || $product->category()->firstOrFail()->requiresApproval();
    }

    private function ensureReadyForBuyers(Product $product): void
    {
        if (! $product->farm?->isApproved()) {
            throw new BusinessRuleException(__('Your farm must be verified before your listings can go live.'), 422);
        }

        if (! $product->category()->where('is_active', true)->exists()) {
            throw new BusinessRuleException(__('This category is no longer available. Choose another category first.'), 422);
        }

        if (! $product->images()->exists()) {
            throw new BusinessRuleException(__('Add at least one photo before publishing.'), 422);
        }

        if (! $product->variants()->where('is_active', true)->exists()) {
            throw new BusinessRuleException(__('Add at least one selling option (a unit and price) before publishing.'), 422);
        }
    }

    private function activate(Product $product): void
    {
        $product->forceFill([
            'status' => ProductStatus::Active,
            'rejection_reason' => null,
            'published_at' => $product->published_at ?? now(),
            'expires_at' => $this->newExpiry(),
        ])->save();
    }

    private function newExpiry(): CarbonInterface
    {
        return now()->addDays(config('mundalinq.catalog.listing_ttl_days'));
    }

    private function transition(Product $product, ProductStatus $from, callable $apply, string $error): Product
    {
        return DB::transaction(function () use ($product, $from, $apply, $error) {
            $product = $this->lock($product);

            if ($product->status !== $from) {
                throw new BusinessRuleException($error);
            }

            $apply($product);

            return $product;
        });
    }

    private function lock(Product $product): Product
    {
        return Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    }

    private function notifyOwners(Product $product, string $title, string $message): void
    {
        $owners = $product->farm?->members()->wherePivot('role', FarmMemberRole::Owner->value)->get() ?? collect();

        foreach ($owners as $owner) {
            $owner->notify(new MarketplaceAlert(NotificationCategory::Listings, $title, $message, [
                'farm_id' => $product->farm_id,
                'product_id' => $product->id,
            ], sms: true));
        }
    }
}
