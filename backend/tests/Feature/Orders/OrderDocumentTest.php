<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class OrderDocumentTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Order} farmer, buyer, an order placed for 5 kg
     */
    private function placedOrder(): array
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '5');

        return [$farmer, $buyer, $this->checkout($buyer)];
    }

    public function test_the_buyer_downloads_a_pdf_receipt(): void
    {
        [, $buyer, $order] = $this->placedOrder();
        Sanctum::actingAs($buyer);

        $response = $this->get("/api/v1/orders/{$order->id}/receipt")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame("inline; filename=\"MundaLinq-{$order->number}.pdf\"", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_returns_404_for_someone_elses_receipt(): void
    {
        [, , $order] = $this->placedOrder();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertNotFound();
    }

    public function test_the_farm_downloads_its_sale_record_and_the_buyer_cannot(): void
    {
        [$farmer, $buyer, $order] = $this->placedOrder();
        $part = $order->sellerOrders()->sole();

        Sanctum::actingAs($farmer);
        $this->assertStringStartsWith('%PDF-', $this->get("/api/v1/seller-orders/{$part->id}/sale-record")->assertOk()->getContent());

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/seller-orders/{$part->id}/sale-record")->assertForbidden();
    }

    public function test_a_signed_link_opens_the_receipt_without_signing_in_until_it_expires(): void
    {
        [, $buyer, $order] = $this->placedOrder();
        Sanctum::actingAs($buyer);
        $path = $this->getJson("/api/v1/orders/{$order->id}/receipt-link")->assertOk()->json('data.path');
        $this->app['auth']->forgetGuards();

        $this->assertStringStartsWith('%PDF-', $this->get($path)->assertOk()->getContent());
        $this->get(str_replace('signature=', 'signature=0', $path))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($path)->assertForbidden();
    }
}
