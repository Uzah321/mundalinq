<?php

namespace App\Contracts;

use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Services\Payments\InitiatedPayment;
use App\Services\Payments\ProviderRefund;
use App\Services\Payments\ProviderStatus;
use App\Services\Payments\WebhookNotification;
use Illuminate\Http\Request;

/**
 * A payment provider (sandbox now; Paynow, EcoCash and others later).
 *
 * The provider is the only source of truth about money: an order is never marked paid on the
 * strength of the app or a webhook body, only on what fetchStatus() reports.
 */
interface PaymentGateway
{
    /**
     * Start collecting the payment. Returns the provider's reference and what the buyer must do next.
     *
     * @throws PaymentProviderException
     */
    public function initiate(Payment $payment): InitiatedPayment;

    /**
     * The provider's current view of the payment.
     *
     * @throws PaymentProviderException
     */
    public function fetchStatus(Payment $payment): ProviderStatus;

    /**
     * Prove a webhook came from the provider and say which payment it concerns.
     *
     * @throws InvalidWebhookException
     */
    public function verifyWebhook(Request $request): WebhookNotification;

    /**
     * Send part or all of a succeeded payment back to the payer. $reference is ours and stays the
     * same when a failed refund is retried, so providers that dedupe on it never pay twice.
     *
     * @throws PaymentProviderException
     */
    public function refund(Payment $payment, int $amountMinor, string $reference): ProviderRefund;
}
