<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AcceptSellerOrderRequest;
use App\Http\Requests\Orders\HandoverRequest;
use App\Http\Requests\Orders\RejectSellerOrderRequest;
use App\Http\Resources\BuyerSellerOrderResource;
use App\Http\Resources\FarmSellerOrderResource;
use App\Models\SellerOrder;
use App\Services\Orders\OrderService;
use App\Services\Orders\SellerOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One farm's part of an order: the farm answers, prepares and hands it over; the buyer confirms receipt.
 */
class SellerOrderController extends Controller
{
    public function __construct(private SellerOrderService $sellerOrders) {}

    public function show(Request $request, SellerOrder $sellerOrder): JsonResource
    {
        Gate::authorize('view', $sellerOrder);

        return $sellerOrder->order->buyer_id === $request->user()->id
            ? $this->forBuyer($sellerOrder)
            : $this->forFarm($sellerOrder);
    }

    public function accept(AcceptSellerOrderRequest $request, SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        return $this->forFarm($this->sellerOrders->accept($sellerOrder, $request->user(), $request->confirmedQuantities()));
    }

    public function reject(RejectSellerOrderRequest $request, SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        return $this->forFarm($this->sellerOrders->reject($sellerOrder, $request->user(), $request->validated('reason')));
    }

    public function ready(Request $request, SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        Gate::authorize('fulfil', $sellerOrder);

        return $this->forFarm($this->sellerOrders->markReady($sellerOrder, $request->user()));
    }

    public function dispatch(Request $request, SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        Gate::authorize('fulfil', $sellerOrder);

        return $this->forFarm($this->sellerOrders->dispatch($sellerOrder, $request->user()));
    }

    /**
     * Body: {"code": "123456"}, the code shown in the buyer's app.
     */
    public function handover(HandoverRequest $request, SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        return $this->forFarm($this->sellerOrders->handOver($sellerOrder, $request->user(), $request->validated('code')));
    }

    public function confirmReceipt(Request $request, SellerOrder $sellerOrder, OrderService $orders): BuyerSellerOrderResource
    {
        Gate::authorize('confirmReceipt', $sellerOrder);

        return $this->forBuyer($orders->confirmReceipt($sellerOrder, $request->user()));
    }

    private function forFarm(SellerOrder $sellerOrder): FarmSellerOrderResource
    {
        return new FarmSellerOrderResource($sellerOrder->load(['order.buyer', 'items', 'dispute']));
    }

    private function forBuyer(SellerOrder $sellerOrder): BuyerSellerOrderResource
    {
        return new BuyerSellerOrderResource($sellerOrder->load(['farm', 'items.product.location', 'review', 'dispute']));
    }
}
