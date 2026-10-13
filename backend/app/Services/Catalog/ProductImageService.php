<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Images\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImageService
{
    public function __construct(private ImageProcessor $processor) {}

    public function add(Product $product, UploadedFile $file): ProductImage
    {
        // Fail before doing the expensive resize; checked again under the lock below.
        $this->ensureRoomFor($product);

        $sizes = config('mundalinq.catalog.image_sizes');
        $images = $this->processor->resize($file->getRealPath(), $sizes);
        $disk = config('mundalinq.catalog.image_disk');
        $base = 'products/'.$product->id.'/'.Str::uuid7();
        $paths = [
            'path' => "{$base}.{$images['large']->extension}",
            'thumb_path' => "{$base}_thumb.{$images['thumb']->extension}",
        ];

        try {
            Storage::disk($disk)->put($paths['path'], $images['large']->data, 'public');
            Storage::disk($disk)->put($paths['thumb_path'], $images['thumb']->data, 'public');

            return DB::transaction(function () use ($product, $images, $disk, $paths) {
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $count = $this->ensureRoomFor($product);

                return $product->images()->create([
                    ...$paths,
                    'disk' => $disk,
                    'width' => $images['large']->width,
                    'height' => $images['large']->height,
                    'size_bytes' => strlen($images['large']->data),
                    'position' => $count,
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete(array_values($paths));

            throw $e;
        }
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $product = Product::whereKey($image->product_id)->lockForUpdate()->firstOrFail();

            if ($product->status->isSubmitted() && $product->images()->count() === 1) {
                throw new BusinessRuleException(__('A published listing needs at least one photo. Add another photo before removing this one.'));
            }

            $image->delete();

            // Keep positions contiguous so position 0 is always the cover photo.
            $product->images()->where('position', '>', $image->position)->decrement('position');
        });

        Storage::disk($image->disk)->delete([$image->path, $image->thumb_path]);
    }

    /**
     * @param  list<string>  $imageIds  Every image of the product, cover first.
     */
    public function reorder(Product $product, array $imageIds): void
    {
        DB::transaction(function () use ($product, $imageIds) {
            foreach ($imageIds as $position => $id) {
                $product->images()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    private function ensureRoomFor(Product $product): int
    {
        $count = $product->images()->count();

        if ($count >= config('mundalinq.catalog.max_images')) {
            throw new BusinessRuleException(__('A listing can have at most :max photos.', ['max' => config('mundalinq.catalog.max_images')]));
        }

        return $count;
    }
}
