<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Checkout\CheckoutService;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer's orders.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()
            ->with(OrderResource::RELATIONS)
            ->latest()
            ->orderByDesc('id')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    /**
     * Place the cart as an order. Send the total from the checkout quote as expected_total:
     * if prices moved since, the order is refused (409) with the new total to confirm.
     */
    public function store(PlaceOrderRequest $request, CheckoutService $checkout): JsonResponse
    {
        $order = $checkout->placeOrder(
            $request->user(),
            $request->address(),
            $request->methods(),
            (string) $request->validated('expected_total'),
            $request->validated('note'),
        );

        return (new OrderResource($order->load(OrderResource::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(OrderResource::RELATIONS));
    }

    /**
     * Allowed until the order is paid, and not while a payment prompt is open.
     */
    public function cancel(Request $request, Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('act', $order);

        return new OrderResource($orders->cancelByBuyer($order, $request->user())->load(OrderResource::RELATIONS));
    }
}
