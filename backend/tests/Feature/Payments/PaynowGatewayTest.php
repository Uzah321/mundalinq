<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Gateways\PaynowGateway;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class PaynowGatewayTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    private const KEY = 'test-integration-key';

    private const POLL_URL = 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=test-guid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mundalinq.payments.provider' => 'paynow',
            'mundalinq.payments.paynow.integration_id' => '1234',
            'mundalinq.payments.paynow.integration_key' => self::KEY,
            'mundalinq.payments.paynow.auth_email' => 'merchant@example.com',
            'mundalinq.payments.paynow.test_mode' => true,
        ]);
    }

    /**
     * A form-encoded message signed the way Paynow signs: SHA-512 of the values in order, then the key.
     *
     * @param  array<string, string>  $fields
     */
    private function signed(array $fields, string $key = self::KEY): string
    {
        return http_build_query([...$fields, 'hash' => strtoupper(hash('sha512', implode('', $fields).$key))]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function statusFields(string $status, array $overrides = []): array
    {
        $payment = Payment::sole();

        return [
            'reference' => $payment->provider_reference,
            'paynowreference' => '987654',
            'amount' => Money::format($payment->amount_minor),
            'status' => $status,
            'pollurl' => self::POLL_URL,
            ...$overrides,
        ];
    }

    private function fakePaynow(string $pollStatus = 'Paid', string $key = self::KEY): void
    {
        Http::fake([
            PaynowGateway::INITIATE_URL => Http::response($this->signed([
                'status' => 'Ok',
                'instructions' => 'Dial *151*2*4# and enter your PIN to approve.',
                'paynowreference' => '987654',
                'pollurl' => self::POLL_URL,
            ])),
            'www.paynow.co.zw/Interface/CheckPayment/*' => fn () => Http::response($this->signed($this->statusFields($pollStatus), $key)),
        ]);
    }

    /**
     * @return array{0: User, 1: Order} buyer and an accepted order of 10 kg at $1.20
     */
    private function orderToPay(): array
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');

        return [$buyer, $this->acceptAll($this->checkout($buyer))];
    }

    private function initiate(Order $order, string $phone = '0771111111'): TestResponse
    {
        return $this->postJson('/api/v1/payments/initiate', ['order_id' => $order->id, 'method' => 'mobile_money', 'phone' => $phone]);
    }

    private function resultPost(string $body): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/payments/paynow', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: $body);
    }

    public function test_starts_an_ecocash_prompt_signed_with_the_integration_key(): void
    {
        $this->fakePaynow();
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $this->initiate($order)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.next_action.message', 'Dial *151*2*4# and enter your PIN to approve.');

        Http::assertSent(function (ClientRequest $request) {
            if ($request->url() !== PaynowGateway::INITIATE_URL) {
                return false;
            }
            $fields = $request->data();
            $hash = Arr::pull($fields, 'hash');

            return $fields['id'] === '1234'
                && $fields['method'] === 'ecocash'
                && $fields['phone'] === '0771111111'
                && $fields['amount'] === '12.00'
                && $fields['authemail'] === 'merchant@example.com'
                && str_ends_with($fields['resulturl'], '/api/v1/webhooks/payments/paynow')
                && $hash === strtoupper(hash('sha512', implode('', $fields).self::KEY));
        });
    }

    public function test_a_paid_answer_from_the_poll_url_pays_the_order(): void
    {
        $this->fakePaynow('Paid');
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);
        $paymentId = $this->initiate($order)->json('data.id');

        $this->getJson("/api/v1/payments/{$paymentId}/status")->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_a_cancelled_prompt_fails_the_payment(): void
    {
        $this->fakePaynow('Cancelled');
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);
        $paymentId = $this->initiate($order)->json('data.id');

        $this->getJson("/api/v1/payments/{$paymentId}/status")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_reason', 'The payment was cancelled or declined on the phone.');

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_an_answer_with_the_wrong_hash_is_not_trusted(): void
    {
        // The prompt starts with a properly signed answer; only the "Paid" poll answer is forged.
        $this->fakePaynow('Paid', key: 'someone-elses-key');
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);
        $paymentId = $this->initiate($order)->json('data.id');

        $this->getJson("/api/v1/payments/{$paymentId}/status")->assertJsonPath('data.status', 'pending');
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_returns_502_with_paynows_reason_when_it_refuses_a_payment(): void
    {
        Http::fake([PaynowGateway::INITIATE_URL => Http::response('status=Error&error=Invalid+mobile+number')]);
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $this->initiate($order)
            ->assertStatus(502)
            ->assertJsonPath('message', 'Paynow could not start the payment: Invalid mobile number');

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
    }

    public function test_returns_502_without_calling_paynow_for_numbers_it_cannot_charge(): void
    {
        Http::fake();
        [$buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $this->initiate($order, '0731234567')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pay with an EcoCash (077 or 078) or OneMoney (071) number.');

        Http::assertNothingSent();
    }

    public function test_a_signed_result_post_makes_us_check_the_poll_url(): void
    {
        $this->fakePaynow('Paid');
        [$buyer, $order] = $this->orderToPay();
        $payment = app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, '+263771111111');

        $this->resultPost($this->signed($this->statusFields('Paid')))->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_returns_401_for_a_result_post_with_the_wrong_hash(): void
    {
        $this->fakePaynow('Paid');
        [$buyer, $order] = $this->orderToPay();
        $payment = app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, '+263771111111');

        $this->resultPost($this->signed($this->statusFields('Paid'), 'someone-elses-key'))->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_refunds_are_left_for_staff_to_send_by_hand(): void
    {
        $this->fakePaynow();
        [$buyer, $order] = $this->orderToPay();
        $payment = app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, '+263771111111');

        $refund = app(PaynowGateway::class)->refund($payment, 500, 'RF-1');

        $this->assertSame(RefundStatus::Failed, $refund->status);
        Http::assertSentCount(1);
    }
}
