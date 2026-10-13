<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Services\Payments\InitiatedPayment;
use App\Services\Payments\ProviderRefund;
use App\Services\Payments\ProviderStatus;
use App\Services\Payments\WebhookNotification;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paynow (paynow.co.zw) Express Checkout: a payment prompt on the buyer's EcoCash or OneMoney phone.
 *
 * Messages both ways are form-encoded and signed with a hash: SHA-512 of the field values in the order
 * they are sent (leaving out the hash itself) followed by the integration key, in upper-case hex.
 * Paynow posts status changes to our result URL; those only prompt us to read the payment's poll URL,
 * whose signed answer decides the outcome.
 */
class PaynowGateway implements PaymentGateway
{
    public const INITIATE_URL = 'https://www.paynow.co.zw/interface/remotetransaction';

    /** Paynow statuses meaning the money has been taken. */
    private const PAID = ['paid', 'awaiting delivery', 'delivered'];

    public function initiate(Payment $payment): InitiatedPayment
    {
        $method = $this->walletFor($payment->payer_phone);
        $payment->loadMissing(['order', 'buyer']);
        // Ours, unique per attempt; Paynow shows it to the buyer and sends it back with every update.
        $reference = $payment->order->number.'-'.Str::upper(Str::substr(str_replace('-', '', $payment->id), -8));

        $fields = [
            'id' => $this->integrationId(),
            'reference' => $reference,
            'amount' => Money::format($payment->amount_minor),
            'additionalinfo' => __(':app order :number', ['app' => config('app.name'), 'number' => $payment->order->number]),
            'returnurl' => (string) config('mundalinq.payments.paynow.return_url'),
            'resulturl' => route('v1.webhooks.payments', ['provider' => 'paynow']),
            'authemail' => $this->authEmail($payment),
            'phone' => $this->localNumber($payment->payer_phone),
            'method' => $method,
            'status' => 'Message',
        ];

        $answer = $this->send(self::INITIATE_URL, [...$fields, 'hash' => $this->sign($fields)]);

        if (empty($answer['pollurl'])) {
            throw new PaymentProviderException('Paynow did not return a poll URL.');
        }

        $wallet = $method === 'ecocash' ? 'EcoCash' : 'OneMoney';

        return new InitiatedPayment($reference, [
            'pollurl' => $answer['pollurl'],
            'paynow_reference' => $answer['paynowreference'] ?? null,
            'method' => $method,
        ], [
            'type' => 'approve_on_phone',
            'message' => $answer['instructions'] ?? __('Check your phone (:phone) and enter your :wallet PIN to pay :amount.', [
                'phone' => $fields['phone'],
                'wallet' => $wallet,
                'amount' => Money::display($payment->amount_minor, $payment->currency),
            ]),
        ]);
    }

    public function fetchStatus(Payment $payment): ProviderStatus
    {
        $pollUrl = $payment->provider_data['pollurl'] ?? throw new PaymentProviderException("Payment [{$payment->id}] has no Paynow poll URL.");
        $answer = $this->send($pollUrl, []);

        if (($answer['reference'] ?? null) !== $payment->provider_reference) {
            throw new PaymentProviderException('Paynow answered for ['.($answer['reference'] ?? 'no reference')."] instead of [{$payment->provider_reference}].");
        }

        $status = Str::lower($answer['status'] ?? '');
        $raw = Arr::only($answer, ['reference', 'paynowreference', 'amount', 'status']);

        return match (true) {
            in_array($status, self::PAID, true) => ProviderStatus::paid(Money::toMinor($answer['amount'] ?? '0'), $raw),
            $status === 'cancelled' => ProviderStatus::failed(__('The payment was cancelled or declined on the phone.'), $raw),
            in_array($status, ['failed', 'refunded'], true) => ProviderStatus::failed(__('Paynow did not complete the payment.'), $raw),
            // Created, Sent (waiting for the buyer), Disputed and anything new: keep waiting.
            default => ProviderStatus::pending($raw),
        };
    }

    public function verifyWebhook(Request $request): WebhookNotification
    {
        $fields = $this->parse($request->getContent());

        try {
            $valid = isset($fields['reference']) && $this->signedCorrectly($fields);
        } catch (PaymentProviderException $e) {
            throw new InvalidWebhookException($e->getMessage());
        }

        if (! $valid) {
            throw new InvalidWebhookException('Missing reference or hash mismatch.');
        }

        // Paynow sends no event id: one delivery per status of a payment is enough.
        $eventId = ($fields['paynowreference'] ?? $fields['reference']).':'.Str::lower($fields['status'] ?? '');

        return new WebhookNotification($eventId, $fields['reference'], Arr::except($fields, 'hash'));
    }

    /**
     * Paynow takes no refunds through its API, so each one is left for staff: they send the money back
     * (Paynow dashboard or mobile money) and record it under Admin › Money › Refunds.
     */
    public function refund(Payment $payment, int $amountMinor, string $reference): ProviderRefund
    {
        return ProviderRefund::failed(__('Paynow does not take refunds through its API. Send the money back from the Paynow dashboard or by mobile money, then mark this refund as paid.'));
    }

    /**
     * Post a form and return Paynow's signed answer.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function send(string $url, array $fields): array
    {
        try {
            $response = Http::asForm()->timeout(30)->post($url, $fields);
        } catch (ConnectionException $e) {
            throw new PaymentProviderException('Paynow could not be reached: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new PaymentProviderException("Paynow answered HTTP {$response->status()}.");
        }

        $answer = $this->parse($response->body());

        // Error answers carry no hash.
        if (Str::lower($answer['status'] ?? '') === 'error') {
            $reason = $answer['error'] ?? 'unknown error';

            throw new PaymentProviderException("Paynow refused the request: {$reason}", __('Paynow could not start the payment: :reason', ['reason' => $reason]));
        }

        if (! $this->signedCorrectly($answer)) {
            throw new PaymentProviderException('Paynow\'s answer failed the hash check.');
        }

        return $answer;
    }

    /**
     * @return array<string, string> In the order Paynow sent them, which the hash depends on.
     */
    private function parse(string $body): array
    {
        parse_str($body, $fields);

        return array_filter($fields, 'is_string');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function sign(array $fields): string
    {
        $values = implode('', Arr::except($fields, ['hash', 'HASH']));

        return Str::upper(hash('sha512', $values.$this->integrationKey()));
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function signedCorrectly(array $fields): bool
    {
        return isset($fields['hash']) && hash_equals($this->sign($fields), Str::upper($fields['hash']));
    }

    /**
     * Paynow wants the wallet's name, which follows from the number's network.
     */
    private function walletFor(string $phone): string
    {
        return match (substr($this->localNumber($phone), 0, 3)) {
            '077', '078' => 'ecocash',
            '071' => 'onemoney',
            default => throw new PaymentProviderException("No Paynow wallet for [{$phone}].", __('Pay with an EcoCash (077 or 078) or OneMoney (071) number.')),
        };
    }

    /**
     * +263771234567 -> 0771234567, the form Paynow expects.
     */
    private function localNumber(string $phone): string
    {
        return '0'.substr((string) preg_replace('/\D/', '', $phone), -9);
    }

    /**
     * Paynow wants an email with every mobile payment. In test mode it must be the merchant account's
     * own; live, the buyer's when they have one.
     */
    private function authEmail(Payment $payment): string
    {
        $merchant = (string) config('mundalinq.payments.paynow.auth_email');
        $buyer = $payment->buyer?->email;

        if (! config('mundalinq.payments.paynow.test_mode') && $buyer) {
            return $buyer;
        }

        return $merchant !== '' ? $merchant : throw new PaymentProviderException('PAYNOW_AUTH_EMAIL is not set.');
    }

    private function integrationId(): string
    {
        return (string) (config('mundalinq.payments.paynow.integration_id') ?: throw new PaymentProviderException('PAYNOW_INTEGRATION_ID is not set.'));
    }

    private function integrationKey(): string
    {
        return (string) (config('mundalinq.payments.paynow.integration_key') ?: throw new PaymentProviderException('PAYNOW_INTEGRATION_KEY is not set.'));
    }
}
