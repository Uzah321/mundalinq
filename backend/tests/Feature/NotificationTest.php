<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_order_updates_land_in_the_inbox_and_can_be_marked_read(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->addToCart($buyer, $this->listing($farm), '5');
        $order = $this->acceptAll($this->checkout($buyer));
        $sellerOrder = $order->sellerOrders()->first();

        Sanctum::actingAs($farmer);
        $this->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.category', 'orders')
            ->assertJsonPath('data.0.title', "New order {$sellerOrder->number}")
            ->assertJsonPath('data.0.context.seller_order_id', $sellerOrder->id)
            ->assertJsonPath('data.0.read_at', null);

        Sanctum::actingAs($buyer);
        $inbox = $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread_count', 1);
        $this->assertSame("Order {$order->number} confirmed", $inbox->json('data.0.title'));
        $this->assertSame($order->id, $inbox->json('data.0.context.order_id'));

        $this->postJson('/api/v1/me/notifications/'.$inbox->json('data.0.id').'/read')
            ->assertOk()
            ->assertJsonPath('data.read_at', fn (?string $readAt) => $readAt !== null);
        $this->getJson('/api/v1/me/notifications?unread=1')
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_read_all_clears_the_badge(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $this->paidOrder($buyer, $this->listing($farm));
        Sanctum::actingAs($farmer);

        $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread_count', 2);
        $this->postJson('/api/v1/me/notifications/read-all')->assertNoContent();
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_another_users_notification_is_not_found(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $this->addToCart($buyer = $this->buyer(), $this->listing($farm), '5');
        $this->checkout($buyer);
        $notificationId = $farmer->notifications()->value('id');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/me/notifications/{$notificationId}/read")->assertNotFound();
        $this->assertNull($farmer->notifications()->value('read_at'));
    }

    public function test_turning_off_sms_for_a_category_keeps_it_in_the_inbox_only(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->patchJson('/api/v1/me/notification-preferences', ['sms' => ['orders' => false]])
            ->assertOk()
            ->assertJsonPath('data.sms.orders', false)
            ->assertJsonPath('data.sms.messages', true);

        $this->addToCart($buyer = $this->buyer(), $this->listing($farm), '5');
        $this->checkout($buyer);

        $this->assertSame([], $this->smsTo($farmer->phone));
        $this->assertSame(1, $farmer->notifications()->count());

        $this->patchJson('/api/v1/me/notification-preferences', ['sms' => ['messages' => false]])
            ->assertJsonPath('data.sms.orders', false)
            ->assertJsonPath('data.sms.messages', false);
    }

    public function test_preferences_only_accept_known_categories_with_true_or_false(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/me/notification-preferences', ['sms' => ['marketing' => false]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sms']);

        $this->patchJson('/api/v1/me/notification-preferences', ['sms' => ['orders' => 'sometimes']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sms.orders' => 'The sms.orders field must be true or false.']);
    }

    public function test_the_inbox_requires_sign_in(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    }
}
