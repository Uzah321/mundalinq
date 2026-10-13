<?php

namespace Tests\Feature\Disputes;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\OrderStatus;
use App\Enums\RoleSlug;
use App\Enums\SellerOrderStatus;
use App\Models\Dispute;
use App\Models\Farm;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Disputes\DisputeService;
use App\Services\Orders\OrderService;
use App\Services\Orders\SellerOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * 10 kg at $1.20 picked up and handed over: $12.00, of which the farm earns $11.40.
     *
     * @return array{0: User, 1: User, 2: Farm, 3: SellerOrder}
     */
    private function deliveredOrder(): array
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $sellerOrder = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);

        return [$buyer, $farmer, $farm, $sellerOrder->fresh()];
    }

    private function openDispute(User $buyer, SellerOrder $sellerOrder): Dispute
    {
        return app(DisputeService::class)->open($sellerOrder, $buyer, DisputeReason::Damaged, 'Half the tomatoes were crushed.', null);
    }

    private function supportAgent(): User
    {
        Sanctum::actingAs($agent = User::factory()->withRole(RoleSlug::SupportAgent)->create());

        return $agent;
    }

    public function test_buyer_reports_a_problem_and_the_earnings_wait_for_a_decision(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->deliveredOrder();
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/orders/{$sellerOrder->order_id}")
            ->assertJsonPath('data.seller_orders.0.report_problem_until', $sellerOrder->delivered_at->addHours(48)->toIso8601String());

        $disputeId = $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispute", [
            'reason' => 'damaged',
            'description' => 'Half the tomatoes were crushed.',
            'requested_refund' => '6.00',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.requested_refund', '6.00')
            ->assertJsonPath('data.seller_order.number', $sellerOrder->number)
            ->json('data.id');

        $this->assertSame(SellerOrderStatus::Disputed, $sellerOrder->fresh()->status);
        $this->getJson("/api/v1/orders/{$sellerOrder->order_id}")->assertJsonPath('data.seller_orders.0.dispute.id', $disputeId);
        $this->assertStringContainsString('The buyer reported that goods arrived damaged', last($this->smsTo($farmer->phone)));

        // The scheduler only finalises undisputed orders.
        $this->travel(3)->days();
        app(OrderService::class)->completeUndisputed();

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")
            ->assertJsonPath('data.pending', '11.40')
            ->assertJsonPath('data.available', '0.00');
        $this->getJson("/api/v1/disputes?farm_id={$farm->id}")->assertJsonPath('data.0.id', $disputeId);
    }

    public function test_problems_are_reported_once_between_handover_and_the_end_of_the_window(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $paid = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        $payload = ['reason' => 'missing_items', 'description' => 'Two bunches were missing.'];
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/seller-orders/{$paid->id}/dispute", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'You can report a problem once the order has been handed over.');

        [$buyer, , , $delivered] = $this->deliveredOrder();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/seller-orders/{$delivered->id}/dispute", [...$payload, 'requested_refund' => '13.00'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot ask for more than the order cost.');
        $this->postJson("/api/v1/seller-orders/{$delivered->id}/dispute", $payload)->assertCreated();
        $this->postJson("/api/v1/seller-orders/{$delivered->id}/dispute", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'A problem has already been reported for this order.');

        [$lateBuyer, , , $late] = $this->deliveredOrder();
        $this->travel(49)->hours();
        Sanctum::actingAs($lateBuyer);
        $this->postJson("/api/v1/seller-orders/{$late->id}/dispute", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'The time to report a problem with this order has passed.');
    }

    public function test_only_the_buyer_reports_problems_and_reasons_must_be_known(): void
    {
        [$buyer, $farmer, , $sellerOrder] = $this->deliveredOrder();
        $payload = ['reason' => 'damaged', 'description' => 'Half the tomatoes were crushed.'];

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispute", $payload)->assertForbidden();

        Sanctum::actingAs($this->buyer());
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispute", $payload)->assertNotFound();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/dispute", ['reason' => 'late', 'description' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'The selected reason is invalid.', 'description' => 'The description field must be at least 10 characters.']);
    }

    public function test_buyer_farm_and_support_talk_in_the_dispute_and_outsiders_cannot_see_it(): void
    {
        [$buyer, $farmer, , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/disputes/{$dispute->id}/messages", ['body' => 'They left here firm, see our packing photo.'])
            ->assertCreated()
            ->assertJsonPath('data.party', 'farm');
        $this->assertSame('They left here firm, see our packing photo.', $buyer->notifications()->where('type', 'disputes')->first()->data['body']);

        $this->supportAgent();
        $this->postJson("/api/v1/disputes/{$dispute->id}/messages", ['body' => 'Thanks both, reviewing now.'])->assertJsonPath('data.party', 'staff');
        $this->assertSame(2, $buyer->notifications()->where('type', 'disputes')->count());

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonMissingPath('data.refunds');

        Sanctum::actingAs($this->buyer());
        $this->getJson("/api/v1/disputes/{$dispute->id}")->assertNotFound();
        $this->postJson("/api/v1/disputes/{$dispute->id}/messages", ['body' => 'Hi'])->assertNotFound();
    }

    public function test_photos_are_kept_private_to_the_people_in_the_dispute(): void
    {
        Storage::fake('local');
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        Sanctum::actingAs($buyer);

        $url = $this->post("/api/v1/disputes/{$dispute->id}/evidence", ['photo' => UploadedFile::fake()->image('crate.jpg', 1200, 900)])
            ->assertOk()
            ->assertJsonPath('data.evidence.0.party', 'buyer')
            ->json('data.evidence.0.url');

        $evidence = $dispute->evidence()->sole();
        Storage::disk('local')->assertExists($evidence->path);
        $this->get($url)->assertOk()->assertHeader('Content-Type', $evidence->mime_type);

        Sanctum::actingAs($this->buyer());
        $this->get($url)->assertNotFound();
    }

    public function test_a_report_holds_a_limited_number_of_photos(): void
    {
        Storage::fake('local');
        config(['mundalinq.disputes.max_evidence' => 1]);
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        Sanctum::actingAs($buyer);

        $this->post("/api/v1/disputes/{$dispute->id}/evidence", ['photo' => UploadedFile::fake()->image('one.jpg')])->assertOk();
        $this->post("/api/v1/disputes/{$dispute->id}/evidence", ['photo' => UploadedFile::fake()->image('two.jpg')])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A report can have at most 1 photos.');
    }

    public function test_a_full_refund_takes_the_sale_back_from_the_farm_and_pays_the_buyer(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        $this->supportAgent();

        $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund_full', 'note' => 'Photos show crushed produce.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.refund_amount', '12.00')
            ->assertJsonPath('data.refunds.0.status', 'succeeded');

        $this->assertSame(SellerOrderStatus::Completed, $sellerOrder->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $sellerOrder->order->fresh()->status);
        $this->assertSame(1200, $sellerOrder->order->payments()->first()->refunded_minor);
        $this->assertStringContainsString('We are refunding $12.00 for order', last($this->smsTo($buyer->phone)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispute.resolved', 'subject_id' => $dispute->id]);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")
            ->assertJsonPath('data.pending', '0.00')
            ->assertJsonPath('data.available', '0.00');
    }

    public function test_a_partial_refund_returns_the_platform_fee_on_the_refunded_goods(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        $this->supportAgent();

        $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund_partial', 'refund_amount' => '5.00', 'note' => 'Part of the crate was crushed.'])
            ->assertOk()
            ->assertJsonPath('data.refund_amount', '5.00');

        // $11.40 earned, less the $5.00 refund, plus $0.25 of the $0.60 fee (5/12 of the goods).
        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")->assertJsonPath('data.available', '6.65');
    }

    public function test_no_refund_releases_the_earnings(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        $this->supportAgent();

        $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'no_refund', 'note' => 'Produce was fine on handover.'])
            ->assertOk()
            ->assertJsonPath('data.refunds', []);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")->assertJsonPath('data.available', '11.40');
        $this->assertStringContainsString('resolved without a refund', last($this->smsTo($farmer->phone)));
    }

    public function test_partial_refunds_need_an_amount_below_the_order_total(): void
    {
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        $this->supportAgent();
        $resolve = "/api/v1/admin/disputes/{$dispute->id}/resolve";

        $this->postJson($resolve, ['resolution' => 'refund_partial', 'note' => 'Some damage.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_amount' => 'The refund amount field is required when resolution is refund_partial.']);
        $this->postJson($resolve, ['resolution' => 'refund_partial', 'refund_amount' => '12.00', 'note' => 'Some damage.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A partial refund must be more than zero and less than the order total. Use a full refund otherwise.');
        $this->postJson($resolve, ['resolution' => 'no_refund', 'refund_amount' => '1.00', 'note' => 'Fine.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_amount']);
    }

    public function test_buyers_and_farmers_cannot_resolve_disputes(): void
    {
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund_full', 'note' => 'Refund me.'])->assertForbidden();
        $this->getJson('/api/v1/admin/disputes')->assertForbidden();
    }

    public function test_the_buyer_withdraws_and_the_sale_completes(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/disputes/{$dispute->id}/withdraw")->assertForbidden();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/disputes/{$dispute->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->postJson("/api/v1/disputes/{$dispute->id}/messages", ['body' => 'One more thing'])
            ->assertConflict()
            ->assertJsonPath('message', 'This report has been closed.');

        $this->assertSame(SellerOrderStatus::Completed, $sellerOrder->fresh()->status);
        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")->assertJsonPath('data.available', '11.40');
    }

    public function test_a_failed_refund_stays_owed_until_retried_or_paid_by_hand(): void
    {
        config(['mundalinq.payments.sandbox.refunds_fail' => true]);
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        $dispute = $this->openDispute($buyer, $sellerOrder);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund_full', 'note' => 'Crushed.'])
            ->assertJsonPath('data.refunds.0.status', 'failed');
        $refundId = $this->getJson('/api/v1/admin/refunds?status=failed')->assertJsonCount(1, 'data')->json('data.0.id');

        $this->postJson("/api/v1/admin/refunds/{$refundId}/retry")->assertOk()->assertJsonPath('data.status', 'failed');

        config(['mundalinq.payments.sandbox.refunds_fail' => false]);
        $this->postJson("/api/v1/admin/refunds/{$refundId}/retry")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->postJson("/api/v1/admin/refunds/{$refundId}/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'Only failed refunds can be retried.');
    }

    public function test_support_records_a_refund_paid_by_hand(): void
    {
        config(['mundalinq.payments.sandbox.refunds_fail' => true]);
        [$buyer, , , $sellerOrder] = $this->deliveredOrder();
        app(DisputeService::class)->resolve($this->openDispute($buyer, $sellerOrder), User::factory()->withRole(RoleSlug::Admin)->create(), DisputeResolution::RefundFull, null, 'Crushed.');
        $admin = $this->actingAsAdmin();
        $refundId = $this->getJson('/api/v1/admin/refunds')->json('data.0.id');

        $this->postJson("/api/v1/admin/refunds/{$refundId}/complete", ['reference' => 'ECOCASH-123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.provider_reference', 'ECOCASH-123456');
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.paid_manually', 'actor_id' => $admin->id]);
    }

    public function test_money_that_arrived_for_a_cancelled_order_is_refunded_once(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '5');
        $order = $this->acceptAll($this->checkout($buyer));
        $payment = $this->pay($order);
        $payment->forceFill(['requires_refund' => true])->save();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/payments?requires_refund=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requires_refund', true);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund")
            ->assertCreated()
            ->assertJsonPath('data.amount', '6.00')
            ->assertJsonPath('data.status', 'succeeded');
        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund")
            ->assertConflict()
            ->assertJsonPath('message', 'This payment was used for its order and is not owed back.');
        $this->assertFalse($payment->fresh()->requires_refund);
    }
}
