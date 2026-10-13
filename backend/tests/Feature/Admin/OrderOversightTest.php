<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class OrderOversightTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_support_finds_an_order_and_sees_its_payments_and_history(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $paid = $this->paidOrder($buyer, $this->listing($farm));
        $this->addToCart($this->buyer(), $this->listing($farm), '1');
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->getJson('/api/v1/admin/orders?status=paid')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid->id);
        $this->getJson('/api/v1/admin/orders?q='.substr($buyer->phone, -7))->assertJsonPath('data.0.number', $paid->number);

        $this->getJson("/api/v1/admin/orders/{$paid->id}")
            ->assertOk()
            ->assertJsonPath('data.buyer.phone', $buyer->phone)
            ->assertJsonPath('data.payments.0.status', 'succeeded')
            ->assertJsonPath('data.history.0.to', 'awaiting_confirmation')
            ->assertJsonPath('data.seller_orders.0.status', 'preparing');
    }

    public function test_buyers_cannot_browse_other_orders(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }
}
