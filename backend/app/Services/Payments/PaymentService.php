<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProviderState;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Collects payment for orders. Only the provider's answer (fetched by us) can mark a payment
 * succeeded; the app and webhook bodies only prompt us to ask.
 */
class PaymentService
{
    public function __construct(private PaymentGatewayManager $gateways, private OrderService $orders) {}

    /**
     * Start a payment prompt for the order's total. A pending prompt is reused, so tapping
     * "Pay" twice never sends two prompts.
     */
    public function initiate(Order $order, User $buyer, PaymentMethod $method, string $phone): Payment
    {
        $provider = $this->gateways->defaultProvider();
        $gateway = $this->gateways->find($provider)
            ?? throw new BusinessRuleException(__('Payments are not available right now.'), 503);

        [$payment, $isNew] = DB::transaction(function () use ($order, $buyer, $method, $phone, $provider) {
            $order = $this->orders->lock($order);

            if ($order->status !== OrderStatus::AwaitingPayment) {
                throw new BusinessRuleException(__('This order is not waiting for payment.'));
            }

            if ($order->payment_due_at->isPast()) {
                throw new BusinessRuleException(__('The time to pay for this order has passed.'));
            }

            $pending = $order->payments()->where('status', PaymentStatus::Pending)->where('expires_at', '>', now())->first();

            if ($pending !== null) {
                return [$pending, false];
            }

            return [$order->payments()->forceCreate([
                'buyer_id' => $buyer->id,
                'provider' => $provider,
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'payer_phone' => $phone,
                'expires_at' => now()->addMinutes(config('mundalinq.payments.attempt_minutes')),
            ]), true];
        });

        if (! $isNew) {
            return $payment;
        }

        // Outside the transaction: never hold database locks while waiting on the network.
        try {
            $started = $gateway->initiate($payment);
        } catch (PaymentProviderException $e) {
            report($e);
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_reason' => $e->buyerMessage ?? __('The payment provider could not be reached.'),
                'failed_at' => now(),
            ])->save();

            throw new BusinessRuleException($e->buyerMessage ?? __('We could not start the payment. Please try again.'), 502);
        }

        $payment->forceFill([
            'provider_reference' => $started->reference,
            'provider_data' => [...$started->data, 'next_action' => $started->nextAction],
        ])->save();

        return $payment;
    }

    /**
     * Ask the provider for the latest status and apply it. Safe to call any number of times.
     */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->status->isFinal() || $payment->provider_reference === null) {
            return $payment;
        }

        $gateway = $this->gateways->find($payment->provider);

        if ($gateway === null) {
            return $payment;
        }

        try {
            $status = $gateway->fetchStatus($payment);
        } catch (PaymentProviderException $e) {
            report($e);

            return $payment;
        }

        return $this->apply($payment, $status);
    }

    /**
     * Scheduled: prompts nobody answered are checked one last time, then given up on.
     */
    public function expireAbandoned(): int
    {
        $expired = 0;

        // Keyset paging: rows leave the "pending" set as we go, which would make offset paging skip some.
        Payment::where('status', PaymentStatus::Pending)->where('expires_at', '<=', now())->lazyById(100)->each(function (Payment $payment) use (&$expired) {
            if ($this->refresh($payment)->status !== PaymentStatus::Pending) {
                return;
            }

            DB::transaction(function () use ($payment, &$expired) {
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($payment->status === PaymentStatus::Pending) {
                    $payment->forceFill([
                        'status' => PaymentStatus::Expired,
                        'failure_reason' => __('The payment was not approved in time.'),
                        'failed_at' => now(),
                    ])->save();
                    $expired++;
                }
            });
        });

        return $expired;
    }

    /**
     * Check any open prompts for an order (used before cancelling it for non-payment).
     */
    public function refreshOpenPayments(Order $order): void
    {
        foreach ($order->payments()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Expired])->get() as $payment) {
            $this->refresh($payment);
        }
    }

    private function apply(Payment $payment, ProviderStatus $status): Payment
    {
        return DB::transaction(function () use ($payment, $status) {
            $order = $this->orders->lock($payment->order_id);
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Already settled by a concurrent request or an earlier webhook: nothing more to do.
            if ($payment->status->isFinal() || $status->state === ProviderState::Pending) {
                return $payment;
            }

            PaymentEvent::forceCreate([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'source' => 'poll',
                'status' => $status->state->value,
                'payload' => $status->raw,
            ]);

            if ($status->state === ProviderState::Failed) {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => $status->reason, 'failed_at' => now()])->save();

                return $payment;
            }

            $payment->forceFill(['status' => PaymentStatus::Succeeded, 'paid_at' => now()]);

            $orderCanTakeIt = $order->status === OrderStatus::AwaitingPayment && $order->total_minor === $payment->amount_minor;

            if ($status->amountMinor !== $payment->amount_minor || ! $orderCanTakeIt) {
                // The money arrived but this order cannot use it (cancelled, already paid, or a wrong amount).
                $payment->requires_refund = true;
                $payment->save();

                Log::warning('Payment received that needs a refund.', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'order_status' => $order->status->value,
                    'reported_amount_minor' => $status->amountMinor,
                ]);

                return $payment;
            }

            $payment->save();
            $this->orders->markPaid($order, $payment);

            return $payment;
        });
    }
}
