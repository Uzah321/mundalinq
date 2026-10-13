<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Transaction visibility and payment exceptions.
 */
class PaymentController extends Controller
{
    /**
     * Newest first. Filters: status, requires_refund (money that arrived for an order that could not take it).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'requires_refund' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $payments = Payment::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($request->has('requires_refund'), fn ($query) => $query->where('requires_refund', $request->boolean('requires_refund')))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    /**
     * Give back a payment the order could not use.
     */
    public function refund(Request $request, Payment $payment, RefundService $refunds): JsonResponse
    {
        $refund = $refunds->refundUnusedPayment($payment, $request->user());

        return (new RefundResource($refund->refresh()))->response()->setStatusCode(201);
    }
}
