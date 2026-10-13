<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\Farm;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_buyer_asks_a_farm_about_a_listing_and_the_farm_replies(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = $this->listing($farm)->product;
        $buyer = User::factory()->create(['name' => 'Rudo Banda']);

        Sanctum::actingAs($buyer);
        $conversationId = $this->postJson('/api/v1/conversations', [
            'farm_id' => $farm->id,
            'product_id' => $product->id,
            'body' => 'Do you have 50 kg for Saturday?',
        ])->assertCreated()
            ->assertJsonPath('data.side', 'buyer')
            ->assertJsonPath('data.from_me', true)
            ->assertJsonPath('data.product.id', $product->id)
            ->json('data.conversation_id');

        $this->assertSame(['Rudo sent you a message on MundaLinq: "Do you have 50 kg for Saturday?" Reply in the app.'], $this->smsTo($farmer->phone));
        $this->assertSame('Message from Rudo', $farmer->notifications()->where('type', 'messages')->value('data')['title']);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/conversations?farm_id={$farm->id}")
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.buyer.name', 'Rudo Banda')
            ->assertJsonPath('data.0.unread_count', 1);
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Yes, collect from 8am.'])
            ->assertCreated()
            ->assertJsonPath('data.side', 'farm');
        $this->getJson("/api/v1/conversations?farm_id={$farm->id}")->assertJsonPath('data.0.unread_count', 0);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message.body', 'Yes, collect from 8am.');
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertJsonPath('data.0.body', 'Yes, collect from 8am.')
            ->assertJsonPath('data.0.from_me', false)
            ->assertJsonPath('data.1.from_me', true);
        $this->postJson("/api/v1/conversations/{$conversationId}/read")->assertNoContent();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_only_the_first_unread_message_is_texted(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($buyer = $this->buyer());

        $conversationId = $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id, 'body' => 'Hello'])->json('data.conversation_id');
        $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id, 'body' => 'Are you there?'])
            ->assertJsonPath('data.conversation_id', $conversationId);

        $this->assertCount(1, $this->smsTo($farmer->phone));
        $this->assertSame(2, $farmer->notifications()->count());
        $this->assertSame(1, Conversation::count());

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/conversations/{$conversationId}/read")->assertNoContent();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'One more thing'])->assertCreated();
        $this->assertCount(2, $this->smsTo($farmer->phone));
    }

    public function test_the_farm_writes_to_a_buyer_about_their_order(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $sellerOrder = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        Sanctum::actingAs($farmer);

        $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id, 'body' => 'Hi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['farm_id' => 'You cannot message your own farm.']);

        $this->postJson('/api/v1/conversations', ['seller_order_id' => $sellerOrder->id, 'body' => 'We are short 2 kg, is that ok?'])
            ->assertCreated()
            ->assertJsonPath('data.side', 'farm')
            ->assertJsonPath('data.seller_order.number', $sellerOrder->number);

        $this->assertSame($buyer->id, Conversation::sole()->buyer_id);
        $this->assertCount(1, array_filter($this->smsTo($buyer->phone), fn (string $sms) => str_contains($sms, 'We are short 2 kg')));

        [, $otherFarm] = $this->farmerWithFarm();
        Sanctum::actingAs($otherFarm->members()->first());
        $this->postJson('/api/v1/conversations', ['seller_order_id' => $sellerOrder->id, 'body' => 'Hi'])->assertNotFound();
    }

    public function test_outsiders_cannot_read_and_staff_can_read_but_not_write(): void
    {
        [, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($this->buyer());
        $conversationId = $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id, 'body' => 'Hello'])->json('data.conversation_id');

        Sanctum::actingAs($this->buyer());
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertNotFound();
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Hi'])->assertNotFound();
        $this->getJson("/api/v1/conversations?farm_id={$farm->id}")->assertForbidden();

        $this->actingAsAdmin();
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Hi'])->assertForbidden();
    }

    public function test_listings_must_belong_to_the_farm_and_the_farm_must_be_visible(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $elsewhere = Product::factory()->listed()->create();
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id, 'product_id' => $elsewhere->id, 'body' => 'Hi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id' => 'This listing is not from this farm.']);

        $this->postJson('/api/v1/conversations', ['farm_id' => Farm::factory()->pending()->create()->id, 'body' => 'Hi'])->assertNotFound();

        $this->postJson('/api/v1/conversations', ['farm_id' => $farm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'The body field is required.']);
    }
}
