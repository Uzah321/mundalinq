<?php

namespace Tests\Feature\Payments;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SellerOrderStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SandboxPayment;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Order} farmer, buyer, an accepted order of 10 kg at $1.20
     */
    private function orderToPay(): array
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');

        return [$farmer, $buyer, $this->acceptAll($this->checkout($buyer))];
    }

    private function initiate(Order $order, string $phone = '0771111111')
    {
        return $this->postJson('/api/v1/payments/initiate', ['order_id' => $order->id, 'method' => 'mobile_money', 'phone' => $phone]);
    }

    public function test_buyer_pays_by_mobile_money_and_the_farm_starts_preparing(): void
    {
        [$farmer, $buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $paymentId = $this->initiate($order)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '12.00')
            ->assertJsonPath('data.payer_phone', SandboxGateway::SUCCESS)
            ->assertJsonPath('data.next_action.type', 'approve_on_phone')
            ->json('data.id');

        // Nothing is paid until the provider says so.
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);

        $this->getJson("/api/v1/payments/{$paymentId}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.next_action', null);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(SellerOrderStatus::Preparing, $order->sellerOrders()->first()->status);
        $this->assertSame([1200, -60], LedgerEntry::orderByDesc('amount_minor')->pluck('amount_minor')->all(), 'Sale and 5% platform fee');
        $this->assertStringContainsString('Payment of $12.00 received', last($this->smsTo($buyer->phone)));
        $this->assertStringContainsString('is paid. Prepare it for collection.', last($this->smsTo($farmer->phone)));
    }

    public function test_tapping_pay_twice_does_not_send_a_second_prompt(): void
    {
        [, $buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $first = $this->initiate($order, '0779999999')->assertCreated()->json('data.id');
        $this->initiate($order, '0779999999')->assertOk()->assertJsonPath('data.id', $first);

        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, SandboxPayment::count());
    }

    public function test_a_declined_prompt_can_be_retried(): void
    {
        [, $buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $declined = $this->initiate($order, '0773333333')->json('data.id');
        $this->getJson("/api/v1/payments/{$declined}/status")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_reason', 'The payment was cancelled on the phone.');

        $broke = $this->initiate($order, '0774444444')->json('data.id');
        $this->getJson("/api/v1/payments/{$broke}/status")
            ->assertJsonPath('data.failure_reason', 'There was not enough money in the account.');

        $paid = $this->initiate($order)->json('data.id');
        $this->getJson("/api/v1/payments/{$paid}/status")->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_a_slow_approval_is_picked_up_by_polling(): void
    {
        [, $buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);

        $id = $this->initiate($order, '0772222222')->json('data.id');
        $this->getJson("/api/v1/payments/{$id}/status")->assertJsonPath('data.status', 'pending');

        $this->travel(31)->seconds();
        $this->getJson("/api/v1/payments/{$id}/status")->assertJsonPath('data.status', 'succeeded');
    }

    public function test_payment_needs_an_order_waiting_for_payment(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        $order = $this->checkout($buyer);
        Sanctum::actingAs($buyer);

        $this->initiate($order)->assertConflict()->assertJsonPath('message', 'This order is not waiting for payment.');

        $this->acceptAll($order);
        $this->travel(181)->minutes();
        $this->initiate($order)->assertConflict()->assertJsonPath('message', 'The time to pay for this order has passed.');
    }

    public function test_only_the_buyer_can_pay_or_see_the_payment(): void
    {
        [, $buyer, $order] = $this->orderToPay();
        Sanctum::actingAs($buyer);
        $id = $this->initiate($order, '0779999999')->json('data.id');
        $this->initiate($order, '12')->assertJsonValidationErrors('phone');

        Sanctum::actingAs($this->buyer());
        $this->initiate($order)->assertNotFound();
        $this->getJson("/api/v1/payments/{$id}/status")->assertNotFound();
    }

    public function test_money_arriving_for_a_cancelled_order_is_flagged_for_refund(): void
    {
        [, $buyer, $order] = $this->orderToPay();
        $payments = app(PaymentService::class);
        $payment = $payments->initiate($order, $buyer, PaymentMethod::MobileMoney, SandboxGateway::DELAYED_SUCCESS);

        // The order is cancelled while the slow approval is still on its way.
        $orders = app(OrderService::class);
        DB::transaction(fn () => $orders->cancel($orders->lock($order), CancellationReason::PaymentOverdue));

        $this->travel(31)->seconds();
        $payment = $payments->refresh($payment);

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertTrue($payment->requires_refund);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
    }
}
