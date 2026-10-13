<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Newest first. Filters: status, q (order number or buyer phone), farm_id, from/to (placed date).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:50'],
            'farm_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $orders = Order::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $term = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($match) => $match
                    ->whereLike('number', $term)
                    ->orWhereHas('sellerOrders', fn ($sellerOrders) => $sellerOrders->whereLike('number', $term))
                    ->orWhereHas('buyer', fn ($buyer) => $buyer->whereLike('phone', $term)));
            })
            ->when($filters['farm_id'] ?? null, fn ($query, string $farmId) => $query->whereHas('sellerOrders', fn ($sellerOrders) => $sellerOrders->where('farm_id', $farmId)))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->with(['buyer', 'sellerOrders.farm'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(OrderResource::RELATIONS));
    }
}
