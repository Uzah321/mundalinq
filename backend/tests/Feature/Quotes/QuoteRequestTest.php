<?php

namespace Tests\Feature\Quotes;

use App\Enums\OrderStatus;
use App\Enums\QuoteOfferStatus;
use App\Enums\QuoteRequestStatus;
use App\Models\Farm;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\QuoteOffer;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class QuoteRequestTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function requestFor(ProductVariant $variant, array $overrides = []): array
    {
        return [
            'category_id' => $variant->product->category_id,
            'title' => 'Roma tomatoes',
            'quantity' => '200',
            'unit' => 'kg',
            'frequency' => 'weekly',
            'needed_by' => now()->addDays(3)->toDateString(),
            'fulfilment' => 'either',
            'province' => 'Mashonaland East',
            'district' => 'Goromonzi',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function offerFrom(Farm $farm, ProductVariant $variant, array $overrides = []): array
    {
        return ['farm_id' => $farm->id, 'product_variant_id' => $variant->id, 'unit_price' => '1.00', 'quantity' => '50', 'fulfilment' => 'pickup', ...$overrides];
    }

    /**
     * A buyer's open request and a farm selling in its category.
     *
     * @return array{0: User, 1: QuoteRequest, 2: User, 3: Farm, 4: ProductVariant}
     */
    private function openRequest(array $overrides = []): array
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);
        $id = $this->postJson('/api/v1/quote-requests', $this->requestFor($variant, $overrides))->assertCreated()->json('data.id');

        return [$buyer, QuoteRequest::findOrFail($id), $farmer, $farm, $variant];
    }

    public function test_farms_in_the_province_selling_that_produce_are_told_about_a_request(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm);
        [$farAwayFarmer, $farAway] = $this->farmerWithFarm();
        $this->listing($farAway, attributes: ['category_id' => $variant->product->category_id]);
        $farAway->locations()->update(['province' => 'Harare']);
        [$otherFarmer, $other] = $this->farmerWithFarm();
        $this->listing($other);
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/quote-requests', $this->requestFor($variant))
            ->assertCreated()
            ->assertJsonPath('data.frequency_label', 'every week')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('meta.farms_told', 1);

        $this->assertStringContainsString('wants 200 kilograms of Roma tomatoes, every week', $this->smsTo($farmer->phone)[0]);
        $this->assertSame([], $this->smsTo($farAwayFarmer->phone));
        $this->assertSame([], $this->smsTo($otherFarmer->phone));
    }

    public function test_returns_422_for_a_date_that_has_passed(): void
    {
        [, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/quote-requests', $this->requestFor($this->listing($farm), ['needed_by' => now()->subDay()->toDateString()]))
            ->assertJsonValidationErrors('needed_by');
    }

    public function test_a_matching_farm_sees_the_request_and_its_offer_reaches_the_buyer(): void
    {
        [$buyer, $request, $farmer, $farm, $variant] = $this->openRequest();
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/farms/{$farm->id}/quote-requests")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Roma tomatoes')
            ->assertJsonPath('data.0.buyer.name', strtok($buyer->name, ' '));
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $variant))->assertCreated();
        // Changing it updates the same offer.
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $variant, ['unit_price' => '0.95']))->assertOk();

        $this->assertStringContainsString("New offer from {$farm->name}", $buyer->notifications()->get()->pluck('data.title')->join('|'));
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/quote-requests/{$request->id}")
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.unit_price', '0.95')
            ->assertJsonPath('data.offers.0.total', '47.50');
    }

    public function test_returns_422_when_the_offer_is_on_another_farms_listing(): void
    {
        [, $request, $farmer, $farm] = $this->openRequest();
        [, $otherFarm] = $this->farmerWithFarm();
        $someoneElses = $this->listing($otherFarm);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $someoneElses))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose one of your listings that is on sale.');
    }

    public function test_accepting_an_offer_places_an_order_for_the_farm_to_confirm_and_declines_the_rest(): void
    {
        [$buyer, $request, $farmer, $farm, $variant] = $this->openRequest();
        [$rivalFarmer, $rival] = $this->farmerWithFarm();
        $rivalVariant = $this->listing($rival, attributes: ['category_id' => $variant->product->category_id]);
        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $variant))->assertCreated();
        Sanctum::actingAs($rivalFarmer);
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($rival, $rivalVariant, ['unit_price' => '1.10']))->assertCreated();
        $offer = QuoteOffer::where('farm_id', $farm->id)->sole();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/quote-offers/{$offer->id}/accept")
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.total', '50.00')
            ->assertJsonPath('data.seller_orders.0.farm.id', $farm->id);

        $this->assertSame(QuoteRequestStatus::Awarded, $request->fresh()->status);
        $this->assertSame(QuoteOfferStatus::Declined, QuoteOffer::where('farm_id', $rival->id)->sole()->status);
        $this->assertSame('50.000', $variant->product->fresh()->stock_reserved);
        $this->assertSame($offer->id, Order::sole()->quote_offer_id);
        $this->assertStringStartsWith('New order', last($this->smsTo($farmer->phone)));
    }

    public function test_a_weekly_offer_can_be_ordered_again_until_it_ends(): void
    {
        [$buyer, $request, $farmer, $farm, $variant] = $this->openRequest();
        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $variant, ['valid_until' => now()->addWeek()->toDateString()]));
        $offer = QuoteOffer::sole();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/quote-offers/{$offer->id}/accept")->assertCreated();

        $this->postJson("/api/v1/quote-offers/{$offer->id}/order")->assertCreated()->assertJsonPath('data.total', '50.00');
        $this->assertSame(2, Order::where('status', OrderStatus::AwaitingConfirmation)->count());

        $this->travel(8)->days();
        $this->postJson("/api/v1/quote-offers/{$offer->id}/order")->assertUnprocessable();
    }

    public function test_returns_422_when_ordering_a_one_off_offer_again(): void
    {
        [$buyer, $request, $farmer, $farm, $variant] = $this->openRequest(['frequency' => 'one_off']);
        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/quote-requests/{$request->id}/offers", $this->offerFrom($farm, $variant));
        $offer = QuoteOffer::sole();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/quote-offers/{$offer->id}/accept")->assertCreated();

        $this->postJson("/api/v1/quote-offers/{$offer->id}/order")->assertUnprocessable();
    }

    public function test_returns_404_for_someone_elses_request(): void
    {
        [, $request] = $this->openRequest();
        Sanctum::actingAs($this->buyer());

        $this->getJson("/api/v1/quote-requests/{$request->id}")->assertNotFound();
    }
}
