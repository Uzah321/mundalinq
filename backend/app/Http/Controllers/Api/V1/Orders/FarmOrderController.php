<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\SellerOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FarmSellerOrderResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A farm's incoming orders, for the farmer app's Orders tab.
 */
class FarmOrderController extends Controller
{
    public function index(Request $request, Farm $farm): AnonymousResourceCollection
    {
        Gate::authorize('update', $farm);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SellerOrderStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $orders = $farm->sellerOrders()
            ->with(['order.buyer', 'items'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return FarmSellerOrderResource::collection($orders);
    }
}
