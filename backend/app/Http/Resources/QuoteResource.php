<?php

namespace App\Http\Resources;

use App\Services\Checkout\Quote;
use App\Services\Checkout\QuoteLine;
use App\Services\Checkout\SellerQuote;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart, or a checkout quote: one group per farm, each becoming one farm order.
 *
 * @property Quote $resource
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'currency' => $quote->currency,
            'sellers' => array_map(fn (SellerQuote $seller) => [
                'farm' => ['id' => $seller->farm->id, 'name' => $seller->farm->name],
                'fulfilment_method' => $seller->method,
                'available_methods' => $seller->availableMethods,
                'items' => array_map(fn (QuoteLine $line) => $this->line($line), $seller->lines),
                'subtotal' => Money::format($seller->subtotalMinor()),
                'delivery_fee' => Money::format($seller->deliveryFeeMinor),
                'total' => Money::format($seller->totalMinor()),
                'problems' => $seller->problems,
            ], $quote->sellers),
            'subtotal' => Money::format($quote->subtotalMinor()),
            'delivery_fee' => Money::format($quote->deliveryFeeMinor()),
            'total' => Money::format($quote->totalMinor()),
            // Every problem in one place, tagged with farm_id / cart_item_id, so the app can point at it.
            'problems' => $quote->allProblems(),
            'can_place_order' => $quote->canPlaceOrder(),
        ];
    }

    private function line(QuoteLine $line): array
    {
        $variant = $line->variant;
        $product = $variant->product;

        return [
            'cart_item_id' => $line->item->id,
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'title' => $product->title,
            'label' => $variant->label,
            'unit' => ['code' => $variant->unit->code, 'name' => $variant->unit->name, 'plural_name' => $variant->unit->plural_name],
            'thumb_url' => $product->coverImage?->thumbUrl(),
            'quantity' => Quantity::format($line->quantity),
            'unit_price' => Money::format($line->unitPriceMinor),
            'line_total' => Money::format($line->lineTotalMinor),
            'available_quantity' => Quantity::format($variant->availableQuantity()),
            'problem' => $line->problem,
        ];
    }
}
