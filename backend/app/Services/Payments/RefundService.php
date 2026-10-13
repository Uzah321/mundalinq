<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\PaymentProviderException;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Gives money back to payers. A refund is recorded (and the amount reserved against the payment)
 * inside the caller's transaction, then sent to the provider once that commits. A failed refund
 * stays owed: support retries it or pays it by hand and records that.
 */
class RefundService
{
    public function __construct(private PaymentGatewayManager $gateways, private AuditLogger $audit) {}

    /**
     * Never more than the payment still has left to refund.
     */
    public function create(Payment $payment, int $amountMinor, ?User $requestedBy, ?SellerOrder $sellerOrder = null, ?Dispute $dispute = null): Refund
    {
        return DB::transaction(function () use ($payment, $amountMinor, $requestedBy, $sellerOrder, $dispute) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Succeeded) {
                throw new BusinessRuleException(__('Only completed payments can be refunded.'));
            }

            if ($amountMinor <= 0 || $amountMinor > $payment->refundableMinor()) {
                throw new BusinessRuleException(__('A refund cannot be more than what is left of the payment.'), 422);
            }

            $payment->forceFill(['refunded_minor' => $payment->refunded_minor + $amountMinor])->save();

            $refund = Refund::forceCreate([
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'seller_order_id' => $sellerOrder?->id,
                'dispute_id' => $dispute?->id,
                'amount_minor' => $amountMinor,
                'currency' => $payment->currency,
                'status' => RefundStatus::Pending,
                'requested_by' => $requestedBy?->id,
            ]);

            DB::afterCommit(fn () => $this->send($refund));

            return $refund;
        });
    }

    /**
     * Money that arrived for an order that could not take it (cancelled, already paid, wrong amount):
     * give back whatever is left of it.
     */
    public function refundUnusedPayment(Payment $payment, User $staff): Refund
    {
        return DB::transaction(function () use ($payment, $staff) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $payment->requires_refund) {
                throw new BusinessRuleException(__('This payment was used for its order and is not owed back.'));
            }

            $refund = $this->create($payment, $payment->refundableMinor(), $staff);
            $payment->forceFill(['requires_refund' => false])->save();

            $this->audit->record($staff, 'payment.refunded', $payment, ['refund_id' => $refund->id, 'amount_minor' => $refund->amount_minor]);

            return $refund;
        });
    }

    /**
     * Ask the provider to send the money. Only pending or failed refunds are sent, so this is safe to repeat.
     */
    public function send(Refund $refund): Refund
    {
        $refund->loadMissing('payment');

        if ($refund->status === RefundStatus::Succeeded) {
            return $refund;
        }

        $gateway = $this->gateways->find($refund->payment->provider);

        if ($gateway === null) {
            return $this->fail($refund, __('The payment provider is not available.'));
        }

        // Outside any transaction: never hold locks while waiting on the network.
        try {
            $result = $gateway->refund($refund->payment, $refund->amount_minor, $refund->id);
        } catch (PaymentProviderException $e) {
            report($e);

            return $this->fail($refund, __('The payment provider could not be reached.'));
        }

        $refund->forceFill([
            'status' => $result->status,
            'provider_reference' => $result->reference ?? $refund->provider_reference,
            'failure_reason' => $result->reason,
            'completed_at' => $result->status === RefundStatus::Succeeded ? now() : null,
        ])->save();

        return $refund;
    }

    /**
     * Support paid the refund outside the system (e.g. a manual mobile money transfer).
     */
    public function markPaidManually(Refund $refund, User $staff, string $reference): Refund
    {
        return DB::transaction(function () use ($refund, $staff, $reference) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($refund->status === RefundStatus::Succeeded) {
                throw new BusinessRuleException(__('This refund has already been paid.'));
            }

            $refund->forceFill([
                'status' => RefundStatus::Succeeded,
                'provider_reference' => $reference,
                'failure_reason' => null,
                'completed_at' => now(),
            ])->save();

            $this->audit->record($staff, 'refund.paid_manually', $refund, ['reference' => $reference, 'amount_minor' => $refund->amount_minor]);

            return $refund;
        });
    }

    private function fail(Refund $refund, string $reason): Refund
    {
        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => $reason])->save();

        return $refund;
    }
}
