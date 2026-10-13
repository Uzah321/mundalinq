<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\InvalidWebhookException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Payment providers call this when something happens to a payment.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGatewayManager $gateways, PaymentService $payments): JsonResponse
    {
        $gateway = $gateways->find($provider) ?? abort(404);

        try {
            $notification = $gateway->verifyWebhook($request);
        } catch (InvalidWebhookException $e) {
            PaymentEvent::forceCreate([
                'provider' => $provider,
                'source' => 'webhook',
                'signature_valid' => false,
                'payload' => ['error' => $e->getMessage()],
            ]);

            return response()->json(['message' => 'Invalid webhook.'], 401);
        }

        $payment = Payment::where('provider', $provider)->where('provider_reference', $notification->reference)->first();

        // Providers retry deliveries; the unique (provider, event_id) key lets each event through once.
        $isNew = PaymentEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'payment_id' => $payment?->id,
            'provider' => $provider,
            'event_id' => $notification->eventId,
            'source' => 'webhook',
            'payload' => json_encode($notification->payload),
            'signature_valid' => true,
            'created_at' => now(),
        ]) === 1;

        if ($isNew && $payment !== null) {
            // The webhook only prompts us to look; the provider's own status decides the outcome.
            $payments->refresh($payment);
        }

        return response()->json(['message' => 'Received.']);
    }
}
