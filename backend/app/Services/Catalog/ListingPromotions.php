<?php

namespace App\Services\Catalog;

use App\Enums\LedgerEntryType;
use App\Exceptions\BusinessRuleException;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settlement\SettlementService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Featured listings: shown first in the market with a badge, for a number of weeks. Farms pay from
 * the earnings they could otherwise be paid out; staff can feature a listing for free.
 */
class ListingPromotions
{
    public function __construct(private SettlementService $settlement, private AuditLogger $audit) {}

    /**
     * @return list<int>
     */
    public function weekOptions(): array
    {
        return config('mundalinq.catalog.featuring.weeks');
    }

    public function priceMinor(int $weeks): int
    {
        return Money::toMinor((string) config('mundalinq.catalog.featuring.price_per_week')) * $weeks;
    }

    /**
     * The farm features its own live listing; the price comes out of its available earnings at once.
     */
    public function buy(Product $product, User $buyer, int $weeks): ProductPromotion
    {
        return DB::transaction(function () use ($product, $buyer, $weeks) {
            // One purchase per farm at a time, so two taps cannot spend the same balance twice.
            $farm = Farm::whereKey($product->farm_id)->lockForUpdate()->firstOrFail();
            $product = $this->lockLive($product);
            $price = $this->priceMinor($weeks);
            $available = $this->settlement->balances($farm)['available_minor'];

            if ($available < $price) {
                throw new BusinessRuleException(__('Featuring for :weeks costs :price, paid from earnings that are ready to pay out. You have :available.', [
                    'weeks' => trans_choice('{1} 1 week|[2,*] :count weeks', $weeks),
                    'price' => Money::display($price, $product->currency),
                    'available' => Money::display(max(0, $available), $product->currency),
                ]), 422);
            }

            $promotion = $this->extend($product, $buyer, $weeks, $price);

            LedgerEntry::forceCreate([
                'farm_id' => $farm->id,
                'type' => LedgerEntryType::Promotion,
                'amount_minor' => -$price,
                'currency' => $product->currency,
                'description' => __('Featured listing: :title, :weeks', [
                    'title' => $product->title,
                    'weeks' => trans_choice('{1} 1 week|[2,*] :count weeks', $weeks),
                ]),
                'available_at' => now(),
            ]);

            $this->audit->record($buyer, 'product.featured', $product, ['farm_id' => $farm->id, 'weeks' => $weeks, 'amount_minor' => $price]);

            return $promotion;
        });
    }

    /**
     * Staff feature a listing for free, e.g. to launch a new farm or as a promotion.
     */
    public function grant(Product $product, User $admin, int $weeks): ProductPromotion
    {
        return DB::transaction(function () use ($product, $admin, $weeks) {
            $product = $this->lockLive($product);
            $promotion = $this->extend($product, $admin, $weeks, 0);

            $this->audit->record($admin, 'product.featured', $product, ['farm_id' => $product->farm_id, 'weeks' => $weeks, 'amount_minor' => 0]);

            return $promotion;
        });
    }

    /**
     * Staff end a listing's featuring early. Nothing is refunded.
     */
    public function stop(Product $product, User $admin): Product
    {
        $product->forceFill(['featured_until' => null])->save();
        $this->audit->record($admin, 'product.featuring_stopped', $product, ['farm_id' => $product->farm_id]);

        return $product;
    }

    private function lockLive(Product $product): Product
    {
        $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail()->load('farm');

        if (! $product->isVisibleToBuyers()) {
            throw new BusinessRuleException(__('Only listings that are on sale can be featured.'), 422);
        }

        return $product;
    }

    /**
     * Featuring again before it ends adds the weeks on to the end.
     */
    private function extend(Product $product, User $actor, int $weeks, int $priceMinor): ProductPromotion
    {
        $starts = $product->featured_until?->isFuture() ? $product->featured_until : now();
        $ends = $starts->copy()->addWeeks($weeks);
        $product->forceFill(['featured_until' => $ends])->save();

        return ProductPromotion::forceCreate([
            'product_id' => $product->id,
            'farm_id' => $product->farm_id,
            'weeks' => $weeks,
            'amount_minor' => $priceMinor,
            'currency' => $product->currency,
            'created_by' => $actor->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);
    }
}
