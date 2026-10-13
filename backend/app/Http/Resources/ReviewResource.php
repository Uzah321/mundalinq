<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Buyers appear by first name and initial only.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->isStaff();

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'buyer' => ['name' => $this->displayName($this->buyer->name)],
            'farm' => ['id' => $this->farm->id, 'name' => $this->farm->name],
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($product) => [
                'id' => $product->id,
                'title' => $product->title,
            ])),
            'farm_response' => $this->farm_response,
            'farm_responded_at' => $this->farm_responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'seller_order_id' => $this->when($staff, $this->seller_order_id),
            'hidden_at' => $this->when($staff, fn () => $this->hidden_at?->toIso8601String()),
            'hidden_reason' => $this->when($staff, $this->hidden_reason),
        ];
    }

    private function displayName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($parts);
        $last = array_pop($parts);

        return $last ? $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : $first;
    }
}
