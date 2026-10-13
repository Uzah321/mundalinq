<?php

namespace App\Http\Controllers\Api\V1\Quotes;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteRequestResource;
use App\Models\Category;
use App\Models\Farm;
use App\Models\Product;
use App\Models\QuoteRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Requests a farm can answer: open ones in its province for what it sells, and any it has offered on.
 */
class FarmQuoteRequestController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        Gate::authorize('update', $farm);

        $sells = Product::where('farm_id', $farm->id)->where('status', ProductStatus::Active)->distinct()->pluck('category_id');
        // A request for "Vegetables" matches a farm selling tomatoes, and one for tomatoes a farm listing under vegetables.
        $categories = $sells->merge(Category::whereKey($sells)->whereNotNull('parent_id')->pluck('parent_id'))
            ->merge(Category::whereIn('parent_id', $sells)->pluck('id'))
            ->unique();
        $province = $farm->primaryLocation()->value('province');
        $memberIds = $farm->members()->pluck('users.id');

        $requests = QuoteRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($open) => $open->open()
                    ->whereIn('category_id', $categories)
                    ->where('province', $province)
                    ->whereNotIn('buyer_id', $memberIds))
                ->orWhereHas('offers', fn ($offer) => $offer->where('farm_id', $farm->id)))
            ->with([
                'category', 'unit', 'buyer',
                'offers' => fn ($offer) => $offer->where('farm_id', $farm->id)->with(['farm', 'variant.product', 'variant.unit', 'quoteRequest']),
            ])
            ->latest()
            ->paginate(20);

        return QuoteRequestResource::collection($requests);
    }
}
