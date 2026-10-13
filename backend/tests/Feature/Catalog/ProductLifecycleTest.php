<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class ProductLifecycleTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    public function test_publishing_requires_a_photo_and_a_selling_option(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $noPhoto = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        $noOption = Product::factory()->withImage()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$noPhoto->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add at least one photo before publishing.');

        $this->postJson("/api/v1/products/{$noOption->id}/publish")->assertUnprocessable();
    }

    public function test_unverified_farms_cannot_publish(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm(approved: false);
        $product = Product::factory()->withVariant()->withImage()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your farm must be verified before your listings can go live.');
    }

    public function test_listing_goes_live_after_admin_approval(): void
    {
        config(['mundalinq.catalog.require_product_approval' => true]);
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withImage()->withStock('50')->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/publish")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();

        $admin = $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->postJson("/api/v1/admin/products/{$product->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $product->refresh();
        $this->assertTrue($product->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertNotNull($product->published_at);
        $this->assertTrue(AuditLog::where('action', 'product.approved')->where('actor_id', $admin->id)->exists());

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonMissingPath('data.status');
    }

    public function test_by_default_only_sensitive_categories_wait_for_an_admin(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $livestock = Category::factory()->requiringApproval()->create();
        $goats = Category::factory()->childOf($livestock)->create();
        $tomatoes = Product::factory()->withVariant()->withImage()->create(['farm_id' => $farm->id]);
        $goat = Product::factory()->withVariant('animal')->withImage()->create([
            'farm_id' => $farm->id,
            'category_id' => $goats->id,
            'stock_unit_id' => Unit::idFor('animal'),
        ]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$tomatoes->id}/publish")->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/products/{$goat->id}/publish")->assertJsonPath('data.status', 'pending_review');
    }

    public function test_rejected_listing_tells_the_farmer_why_and_can_be_republished(): void
    {
        config(['mundalinq.catalog.require_product_approval' => true]);
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withImage()->pendingReview()->create(['farm_id' => $farm->id, 'title' => 'Roma tomatoes']);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->id}/reject")->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/products/{$product->id}/reject", ['reason' => 'Photo shows a different product'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $sms = end($this->sms->messages);
        $this->assertSame($farmer->phone, $sms['phone']);
        $this->assertStringContainsString('"Roma tomatoes" is not live: Photo shows a different product.', $sms['message']);

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.rejection_reason', 'Photo shows a different product');
        $this->postJson("/api/v1/products/{$product->id}/publish")
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_admin_can_take_down_a_live_listing(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->id}/reject", ['reason' => 'Prohibited item'])->assertOk();

        $this->assertSame(ProductStatus::Rejected, $product->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'product.taken_down')->exists());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_review_actions_are_for_admins_only(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->withVariant()->withImage()->pendingReview()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/admin/products/{$product->id}/approve")->assertForbidden();
    }

    public function test_approval_returns_422_while_the_farm_is_not_verified(): void
    {
        [, $farm] = $this->farmerWithFarm(approved: false);
        $product = Product::factory()->withVariant()->withImage()->pendingReview()->create(['farm_id' => $farm->id]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This farm is not verified, so its listings cannot go live. Verify the farm first.');

        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);
    }

    public function test_listings_are_only_reviewed_once(): void
    {
        $product = Product::factory()->listed()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->id}/approve")->assertConflict();
    }

    public function test_pause_resume_and_expiry(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $product = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/products/{$product->id}/pause")->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/products/{$product->id}/pause")->assertConflict();

        // Paused past its expiry: resuming starts a fresh listing period.
        $this->travel(31)->days();
        $this->postJson("/api/v1/products/{$product->id}/resume")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_expired', false);

        // Left alone, it expires and drops out of search.
        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();

        Sanctum::actingAs($farmer);
        $this->postJson("/api/v1/products/{$product->id}/renew")->assertJsonPath('data.is_expired', false);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
    }
}
