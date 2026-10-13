<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\InitiatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    /**
     * Send the buyer a payment prompt for the order's total. While one is pending, the same payment
     * is returned (200) instead of sending another prompt.
     */
    public function initiate(InitiatePaymentRequest $request, PaymentService $payments): JsonResponse
    {
        $payment = $payments->initiate(
            $request->order(),
            $request->user(),
            PaymentMethod::from($request->validated('method')),
            $request->validated('phone'),
        );

        return (new PaymentResource($payment))->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Poll this while the buyer approves the prompt: each call asks the provider for news.
     */
    public function status(Payment $payment, PaymentService $payments): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payments->refresh($payment));
    }
}
