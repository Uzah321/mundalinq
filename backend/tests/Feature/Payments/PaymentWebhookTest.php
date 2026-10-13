<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    private function webhook(array $body, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $json = json_encode($body);

        return $this->call('POST', '/api/v1/webhooks/payments/sandbox', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SANDBOX_SIGNATURE' => SandboxGateway::signatureHeader(
                $json,
                $secret ?? config('mundalinq.payments.sandbox.webhook_secret'),
                $timestamp ?? now()->getTimestamp(),
            ),
        ], content: $json);
    }

    private function pendingPayment(string $phone = SandboxGateway::SUCCESS): Payment
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        $order = $this->acceptAll($this->checkout($buyer));

        return app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, $phone);
    }

    public function test_a_signed_webhook_makes_us_check_with_the_provider(): void
    {
        $payment = $this->pendingPayment();

        $this->webhook(['event_id' => 'evt_1', 'reference' => $payment->provider_reference, 'status' => 'paid'])->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $payment->order->fresh()->status);
    }

    public function test_replayed_webhooks_are_processed_once(): void
    {
        $payment = $this->pendingPayment();
        $body = ['event_id' => 'evt_1', 'reference' => $payment->provider_reference];

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $this->assertSame(1, PaymentEvent::where('source', 'webhook')->count());
        $this->assertSame(2, LedgerEntry::count(), 'One sale and one fee, recorded once');
    }

    public function test_a_webhook_cannot_mark_a_payment_paid_by_itself(): void
    {
        // The provider still has this prompt pending, whatever the webhook body claims.
        $payment = $this->pendingPayment('0779999999');

        $this->webhook(['event_id' => 'evt_1', 'reference' => $payment->provider_reference, 'status' => 'paid'])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_forged_stale_or_unsigned_webhooks_are_refused(): void
    {
        $payment = $this->pendingPayment();
        $body = ['event_id' => 'evt_1', 'reference' => $payment->provider_reference];

        $this->webhook($body, secret: 'not-the-secret')->assertUnauthorized();
        $this->webhook($body, timestamp: now()->subMinutes(10)->getTimestamp())->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/payments/sandbox', $body)->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(3, PaymentEvent::where('signature_valid', false)->count());
    }

    public function test_unknown_providers_and_payments(): void
    {
        $this->postJson('/api/v1/webhooks/payments/nope', [])->assertNotFound();
        $this->webhook(['event_id' => 'evt_9', 'reference' => 'SBX-UNKNOWN'])->assertOk();
    }
}
