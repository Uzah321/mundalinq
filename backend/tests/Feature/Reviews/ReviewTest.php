<?php

namespace Tests\Feature\Reviews;

use App\Models\Farm;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\SellerOrderService;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * Bought, handed over and confirmed by the buyer.
     *
     * @return array{0: User, 1: User, 2: Farm, 3: SellerOrder}
     */
    private function completedOrder(?User $buyer = null, ?Farm $farm = null): array
    {
        if ($farm === null) {
            [$farmer, $farm] = $this->farmerWithFarm();
        } else {
            $farmer = $farm->members()->first();
        }

        $buyer ??= $this->buyer();
        $sellerOrder = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);
        app(OrderService::class)->confirmReceipt($sellerOrder, $buyer);

        return [$buyer, $farmer, $farm, $sellerOrder->fresh()];
    }

    public function test_buyer_reviews_a_completed_order_and_the_ratings_update(): void
    {
        [$buyer, $farmer, $farm, $sellerOrder] = $this->completedOrder(User::factory()->create(['name' => 'Tendai Chipo Moyo']));
        $productId = $sellerOrder->items()->value('product_id');
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/review", ['rating' => 4, 'comment' => 'Firm and sweet.'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.buyer.name', 'Tendai M.')
            ->assertJsonPath('data.products.0.id', $productId)
            ->assertJsonMissingPath('data.hidden_at');

        $this->getJson("/api/v1/farms/{$farm->id}")
            ->assertJsonPath('data.rating.average', '4.00')
            ->assertJsonPath('data.rating.count', 1);
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.rating.average', '4.00');
        $this->getJson("/api/v1/orders/{$sellerOrder->order_id}")->assertJsonPath('data.seller_orders.0.review.rating', 4);

        $this->getJson("/api/v1/farms/{$farm->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.comment', 'Firm and sweet.')
            ->assertJsonPath('meta.summary.average', '4.00')
            ->assertJsonPath('meta.summary.stars.4', 1)
            ->assertJsonPath('meta.summary.stars.5', 0);
        $this->getJson("/api/v1/products/{$productId}/reviews")->assertJsonCount(1, 'data');

        $this->assertSame('New 4-star review', $farmer->notifications()->where('type', 'reviews')->first()->data['title']);
    }

    public function test_the_farm_rating_averages_every_visible_review(): void
    {
        [, , $farm, $first] = $this->completedOrder();
        [, , , $second] = $this->completedOrder(farm: $farm);
        $reviews = app(ReviewService::class);

        $reviews->create($first, $first->order->buyer, 5, null);
        $reviews->create($second, $second->order->buyer, 2, null);

        $this->assertSame('3.50', $farm->fresh()->rating_average);
        $this->assertSame(2, $farm->fresh()->rating_count);
    }

    public function test_orders_can_be_reviewed_once_after_completion_and_within_the_window(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $paid = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/seller-orders/{$paid->id}/review", ['rating' => 5])
            ->assertConflict()
            ->assertJsonPath('message', 'You can review an order once it is complete.');

        [$buyer, , , $completed] = $this->completedOrder();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/seller-orders/{$completed->id}/review", ['rating' => 5])->assertCreated();
        $this->postJson("/api/v1/seller-orders/{$completed->id}/review", ['rating' => 1])
            ->assertConflict()
            ->assertJsonPath('message', 'You have already reviewed this order.');

        [$lateBuyer, , , $old] = $this->completedOrder();
        $this->travel(config('mundalinq.reviews.window_days') + 1)->days();
        Sanctum::actingAs($lateBuyer);
        $this->postJson("/api/v1/seller-orders/{$old->id}/review", ['rating' => 5])
            ->assertConflict()
            ->assertJsonPath('message', 'The time to review this order has passed.');
    }

    public function test_only_the_buyer_can_review(): void
    {
        [, $farmer, , $sellerOrder] = $this->completedOrder();

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/review", ['rating' => 5])->assertForbidden();

        Sanctum::actingAs($this->buyer());
        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/review", ['rating' => 5])->assertNotFound();
    }

    public function test_rating_must_be_one_to_five_stars(): void
    {
        [$buyer, , , $sellerOrder] = $this->completedOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/seller-orders/{$sellerOrder->id}/review", ['rating' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating' => 'The rating field must be between 1 and 5.']);
    }

    public function test_the_farm_replies_once_and_the_buyer_is_told(): void
    {
        [$buyer, $farmer, , $sellerOrder] = $this->completedOrder();
        $review = app(ReviewService::class)->create($sellerOrder, $buyer, 3, 'A bit soft.');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/reviews/{$review->id}/response", ['response' => 'Not my farm'])->assertForbidden();

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/reviews/{$review->id}/response", ['response' => 'Sorry! Next crate is on us.'])
            ->assertOk()
            ->assertJsonPath('data.farm_response', 'Sorry! Next crate is on us.');
        $this->postJson("/api/v1/reviews/{$review->id}/response", ['response' => 'Again'])->assertConflict();

        $this->assertSame('Sorry! Next crate is on us.', $buyer->notifications()->where('type', 'reviews')->first()->data['body']);
    }

    public function test_hidden_reviews_leave_listings_and_ratings_until_restored(): void
    {
        [$buyer, , $farm, $sellerOrder] = $this->completedOrder();
        $review = app(ReviewService::class)->create($sellerOrder, $buyer, 1, 'Spam spam spam');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Advertising'])->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Advertising'])
            ->assertOk()
            ->assertJsonPath('data.hidden_reason', 'Advertising');
        $this->assertNull($farm->fresh()->rating_average);
        $this->assertSame(0, $farm->fresh()->rating_count);
        $this->getJson("/api/v1/farms/{$farm->id}/reviews")->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/reviews?hidden=1')->assertJsonPath('data.0.id', $review->id);

        $this->postJson("/api/v1/admin/reviews/{$review->id}/unhide")->assertOk()->assertJsonPath('data.hidden_at', null);
        $this->assertSame('1.00', $farm->fresh()->rating_average);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.hidden', 'subject_id' => $review->id]);
    }
}
