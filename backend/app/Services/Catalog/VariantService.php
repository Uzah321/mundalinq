<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class VariantService
{
    public function create(Product $product, VariantSpec $spec): ProductVariant
    {
        return DB::transaction(function () use ($product, $spec) {
            $variant = $product->variants()->create([
                ...$spec->attributes(),
                'sort_order' => ($product->variants()->max('sort_order') ?? -1) + 1,
            ]);

            $this->syncTiers($variant, $spec->tiers() ?? []);

            return $variant;
        });
    }

    public function update(ProductVariant $variant, VariantSpec $spec): ProductVariant
    {
        return DB::transaction(function () use ($variant, $spec) {
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $variant->update($spec->attributes());

            if ($spec->tiers() !== null) {
                $this->syncTiers($variant, $spec->tiers());
            }

            $this->ensureStillSellable($product);

            return $variant->load(['unit', 'priceTiers']);
        });
    }

    public function delete(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant) {
            $product = Product::whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $variant->delete();

            $this->ensureStillSellable($product);
        });
    }

    /**
     * A published listing must always keep at least one way to buy it.
     */
    private function ensureStillSellable(Product $product): void
    {
        if ($product->status->isSubmitted() && ! $product->variants()->where('is_active', true)->exists()) {
            throw new BusinessRuleException(__('A published listing needs at least one active selling option. Add another option or pause the listing first.'));
        }
    }

    /**
     * @param  list<array{min_quantity: string, price_minor: int}>  $tiers
     */
    private function syncTiers(ProductVariant $variant, array $tiers): void
    {
        $variant->priceTiers()->delete();
        $variant->priceTiers()->createMany($tiers);
    }
}
