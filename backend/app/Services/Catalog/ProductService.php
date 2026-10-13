<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Models\Farm;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        private VariantService $variants,
        private InventoryService $inventory,
        private ProductLifecycle $lifecycle,
    ) {}

    /**
     * Create a draft listing with its selling options and, optionally, its first stock batch.
     *
     * @param  list<VariantSpec>  $variants
     * @param  array{quantity: string, harvested_on?: ?string, best_before?: ?string, notes?: ?string}|null  $initialStock
     */
    public function create(Farm $farm, User $actor, array $attributes, array $variants, ?array $initialStock): Product
    {
        return DB::transaction(function () use ($farm, $actor, $attributes, $variants, $initialStock) {
            $product = new Product($attributes);
            $product->farm_id = $farm->id;
            $product->save();

            foreach ($variants as $spec) {
                $this->variants->create($product, $spec);
            }

            if ($initialStock !== null) {
                $this->inventory->receive(
                    $product,
                    $actor,
                    $initialStock['quantity'],
                    $initialStock['harvested_on'] ?? null,
                    $initialStock['best_before'] ?? null,
                    $initialStock['notes'] ?? null,
                );
            }

            return $product->refresh();
        });
    }

    /**
     * Changing what is being sold (title or category) on a published listing sends it back to review
     * when review applies; descriptions, prices, photos and stock can change freely.
     */
    public function update(Product $product, array $attributes): Product
    {
        $product->fill($attributes);

        if ($product->isDirty(['title', 'category_id'])
            && in_array($product->status, [ProductStatus::Active, ProductStatus::Paused], true)
            && $this->lifecycle->needsApproval($product)) {
            $product->status = ProductStatus::PendingReview;
        }

        $product->save();

        return $product;
    }
}
