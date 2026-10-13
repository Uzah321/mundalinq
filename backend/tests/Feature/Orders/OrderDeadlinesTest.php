<?php

namespace Tests\Feature\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SellerOrderStatus;
use App\Models\LedgerEntry;
use App\Services\Orders\SellerOrderService;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

/**
 * The scheduled orders:process-deadlines command.
 */
class OrderDeadlinesTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    private function deadlines(): void
    {
        $this->artisan('orders:process-deadlines')->assertSuccessful();
    }

    public function test_unanswered_orders_expire_and_free_their_stock(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        $order = $this->checkout($buyer);

        $this->deadlines();
        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->fresh()->status);

        $this->travel(241)->minutes();
        $this->deadlines();

        $order->refresh();
        $this->assertSame(SellerOrderStatus::Expired, $order->sellerOrders()->first()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancellationReason::NotConfirmed, $order->cancellation_reason);
        $this->assertSame('0.000', $variant->product->fresh()->stock_reserved);
    }

    public function test_unpaid_orders_are_cancelled_after_the_payment_window(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        $this->addToCart($buyer, $variant, '10');
        $order = $this->acceptAll($this->checkout($buyer));

        $this->travel(181)->minutes();
        $this->deadlines();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancellationReason::PaymentOverdue, $order->cancellation_reason);
        $this->assertSame(SellerOrderStatus::Cancelled, $order->sellerOrders()->first()->status);
        $this->assertSame('0.000', $variant->product->fresh()->stock_reserved);
        $this->assertStringContainsString('was cancelled because it was not paid in time.', last($this->smsTo($buyer->phone)));
    }

    public function test_a_payment_approved_at_the_last_moment_wins_over_cancellation(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        $order = $this->acceptAll($this->checkout($buyer));
        app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, SandboxGateway::DELAYED_SUCCESS);

        // Nobody polled; the window closes, but the provider did take the money.
        $this->travel(181)->minutes();
        $this->deadlines();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Succeeded, $order->payments()->first()->status);
    }

    public function test_abandoned_payment_prompts_expire(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '10');
        $order = $this->acceptAll($this->checkout($buyer));
        $payment = app(PaymentService::class)->initiate($order, $buyer, PaymentMethod::MobileMoney, '+263779999999');

        $this->travel(16)->minutes();
        $this->deadlines();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame('The payment was not approved in time.', $payment->failure_reason);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status, 'The buyer can still try again');
    }

    public function test_handed_over_orders_become_final_after_the_dispute_window(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $order = $this->paidOrder($this->buyer(), $this->listing($farm));
        $sellerOrder = $order->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);

        $this->travel(47)->hours();
        $this->deadlines();
        $this->assertSame(SellerOrderStatus::Delivered, $sellerOrder->fresh()->status);

        $this->travel(2)->hours();
        $this->deadlines();
        $this->assertSame(SellerOrderStatus::Completed, $sellerOrder->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(0, LedgerEntry::whereNull('available_at')->count());

        $this->deadlines(); // running again changes nothing
    }
}
