<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\SandboxPayment;
use App\Services\Payments\InitiatedPayment;
use App\Services\Payments\ProviderRefund;
use App\Services\Payments\ProviderStatus;
use App\Services\Payments\WebhookNotification;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A pretend mobile money provider for development and testing. No money moves.
 *
 * Like Paynow's test mode, the payer's number decides what happens to the payment prompt.
 * Any other number behaves like a prompt nobody answers: it stays pending until it expires.
 */
class SandboxGateway implements PaymentGateway
{
    public const SUCCESS = '+263771111111';

    /** Approved after `mundalinq.payments.sandbox.delayed_success_seconds`. */
    public const DELAYED_SUCCESS = '+263772222222';

    public const CANCELLED = '+263773333333';

    public const INSUFFICIENT_FUNDS = '+263774444444';

    /** Webhooks older or newer than this are refused, so a captured one cannot be replayed later. */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function initiate(Payment $payment): InitiatedPayment
    {
        $sandbox = SandboxPayment::create([
            'reference' => 'SBX-'.Str::upper(Str::random(16)),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'phone' => $payment->payer_phone,
            'status' => 'pending',
        ]);

        return new InitiatedPayment($sandbox->reference, [], [
            'type' => 'approve_on_phone',
            'message' => __('Approve the payment of :amount :currency on :phone. (Sandbox: no real money moves.)', [
                'amount' => Money::format($payment->amount_minor),
                'currency' => $payment->currency,
                'phone' => $payment->payer_phone,
            ]),
        ]);
    }

    public function fetchStatus(Payment $payment): ProviderStatus
    {
        $sandbox = SandboxPayment::where('reference', $payment->provider_reference)->first()
            ?? throw new PaymentProviderException("Unknown sandbox payment [{$payment->provider_reference}].");

        if ($sandbox->status === 'pending') {
            $sandbox->update(['status' => $this->outcome($sandbox)]);
        }

        $raw = $sandbox->only(['reference', 'status', 'amount_minor']);

        return match ($sandbox->status) {
            'paid' => ProviderStatus::paid($sandbox->amount_minor, $raw),
            'cancelled' => ProviderStatus::failed(__('The payment was cancelled on the phone.'), $raw),
            'insufficient_funds' => ProviderStatus::failed(__('There was not enough money in the account.'), $raw),
            default => ProviderStatus::pending($raw),
        };
    }

    public function verifyWebhook(Request $request): WebhookNotification
    {
        $signature = [];
        foreach (explode(',', (string) $request->header('X-Sandbox-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);

        if (abs(now()->getTimestamp() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            throw new InvalidWebhookException('Missing or stale timestamp.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->secret());

        if (! hash_equals($expected, $signature['v1'] ?? '')) {
            throw new InvalidWebhookException('Signature mismatch.');
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ! is_string($body['event_id'] ?? null) || ! is_string($body['reference'] ?? null)) {
            throw new InvalidWebhookException('Malformed body.');
        }

        return new WebhookNotification($body['event_id'], $body['reference'], $body);
    }

    /**
     * The signature header the sandbox sends with a webhook body, for tests and local tooling.
     */
    /**
     * Refunds go through at once, unless mundalinq.payments.sandbox.refunds_fail is set (to exercise failures).
     */
    public function refund(Payment $payment, int $amountMinor, string $reference): ProviderRefund
    {
        if (config('mundalinq.payments.sandbox.refunds_fail')) {
            return ProviderRefund::failed(__('The sandbox is set to refuse refunds.'));
        }

        return ProviderRefund::succeeded('SBX-RF-'.Str::upper(Str::random(12)), [
            'payment_reference' => $payment->provider_reference,
            'refund_reference' => $reference,
            'amount_minor' => $amountMinor,
        ]);
    }

    public static function signatureHeader(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function outcome(SandboxPayment $sandbox): string
    {
        $delay = config('mundalinq.payments.sandbox.delayed_success_seconds');

        return match ($sandbox->phone) {
            self::SUCCESS => 'paid',
            self::DELAYED_SUCCESS => $sandbox->created_at->addSeconds($delay)->isPast() ? 'paid' : 'pending',
            self::CANCELLED => 'cancelled',
            self::INSUFFICIENT_FUNDS => 'insufficient_funds',
            default => 'pending',
        };
    }

    private function secret(): string
    {
        return (string) config('mundalinq.payments.sandbox.webhook_secret');
    }
}
