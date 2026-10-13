<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$farmer, $farm] = $this->farmerWithFarm();
        $this->product = Product::factory()->withVariant()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);
    }

    private function upload(UploadedFile $file)
    {
        return $this->postJson("/api/v1/products/{$this->product->id}/images", ['image' => $file]);
    }

    public function test_photos_are_resized_and_stored_as_compact_copies(): void
    {
        $this->upload(UploadedFile::fake()->image('harvest.jpg', 3000, 2000))
            ->assertCreated()
            ->assertJsonPath('data.width', 1600)
            ->assertJsonPath('data.height', 1067)
            ->assertJsonPath('data.position', 0);

        $image = ProductImage::sole();
        $large = getimagesizefromstring(Storage::disk('public')->get($image->path));
        $thumb = getimagesizefromstring(Storage::disk('public')->get($image->thumb_path));

        $this->assertSame([1600, 1067, IMAGETYPE_WEBP], [$large[0], $large[1], $large[2]]);
        $this->assertSame([480, 320], [$thumb[0], $thumb[1]]);
    }

    public function test_small_photos_are_not_enlarged(): void
    {
        $this->upload(UploadedFile::fake()->image('small.png', 800, 600))
            ->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600);
    }

    public function test_files_that_are_not_photos_are_refused(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('photo.jpg', 'definitely not a jpeg'))
            ->assertJsonValidationErrors('image');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_oversized_photos_are_refused_before_decoding(): void
    {
        config(['mundalinq.catalog.image_max_megapixels' => 1]);

        $this->upload(UploadedFile::fake()->image('huge.jpg', 1200, 1000))
            ->assertJsonValidationErrors('image');
    }

    public function test_a_listing_has_a_photo_limit(): void
    {
        config(['mundalinq.catalog.max_images' => 2]);

        $this->upload(UploadedFile::fake()->image('a.jpg', 400, 300))->assertCreated();
        $this->upload(UploadedFile::fake()->image('b.jpg', 400, 300))->assertCreated();
        $this->upload(UploadedFile::fake()->image('c.jpg', 400, 300))->assertConflict();

        $this->assertCount(4, Storage::disk('public')->allFiles(), 'The refused photo leaves no files behind');
    }

    public function test_photos_can_be_reordered_and_removed(): void
    {
        $ids = collect(['a', 'b', 'c'])
            ->map(fn ($name) => $this->upload(UploadedFile::fake()->image("{$name}.jpg", 400, 300))->json('data.id'))
            ->all();
        [$a, $b, $c] = $ids;

        $this->putJson("/api/v1/products/{$this->product->id}/images/order", ['image_ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $c);

        $this->putJson("/api/v1/products/{$this->product->id}/images/order", ['image_ids' => [$c, $a]])
            ->assertJsonValidationErrors('image_ids');

        $removed = ProductImage::findOrFail($a);
        $this->deleteJson("/api/v1/products/{$this->product->id}/images/{$a}")->assertNoContent();

        $this->assertSame([$c => 0, $b => 1], ProductImage::orderBy('position')->pluck('position', 'id')->all());
        Storage::disk('public')->assertMissing([$removed->path, $removed->thumb_path]);
    }

    public function test_the_last_photo_of_a_published_listing_stays(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $listed = Product::factory()->listed()->create(['farm_id' => $farm->id]);
        Sanctum::actingAs($farmer);

        $image = $listed->images()->sole();
        $this->deleteJson("/api/v1/products/{$listed->id}/images/{$image->id}")->assertConflict();
    }

    public function test_other_farmers_cannot_add_photos(): void
    {
        [$outsider] = $this->farmerWithFarm();
        Sanctum::actingAs($outsider);

        // A draft is invisible to outsiders, so it looks missing rather than forbidden.
        $this->upload(UploadedFile::fake()->image('x.jpg', 400, 300))->assertNotFound();
    }
}
