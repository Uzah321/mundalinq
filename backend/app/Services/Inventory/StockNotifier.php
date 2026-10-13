<?php

namespace App\Services\Inventory;

use App\Enums\NotificationCategory;
use App\Models\Product;
use App\Notifications\MarketplaceAlert;
use App\Support\Quantity;

class StockNotifier
{
    /**
     * The listing just reached the farm's low-stock level.
     */
    public function lowStock(Product $product): void
    {
        $product->loadMissing(['farm.members', 'stockUnit']);
        $available = $product->availableStock();

        $alert = new MarketplaceAlert(
            NotificationCategory::Listings,
            __('Running low: :title', ['title' => $product->title]),
            __(':title is down to :quantity :unit. Add stock in the :app app so buyers can keep ordering.', [
                'title' => $product->title,
                'quantity' => Quantity::format($available),
                'unit' => $product->stockUnit->nameFor($available),
                'app' => config('app.name'),
            ]),
            ['farm_id' => $product->farm_id, 'product_id' => $product->id],
            sms: true,
        );

        foreach ($product->farm->members as $member) {
            $member->notify($alert);
        }
    }
}
