<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\FarmResource as BaseFarmResource;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Staff view of a farm: who owns it and how to reach them.
 *
 * @mixin Farm
 */
class FarmResource extends BaseFarmResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'owners' => $this->whenLoaded('owners', fn () => $this->owners->map(fn (User $owner) => [
                'id' => $owner->id,
                'name' => $owner->name,
                'phone' => $owner->phone,
                'phone_verified' => $owner->hasVerifiedPhone(),
                'email' => $owner->email,
            ])),
            'listings_count' => $this->whenCounted('products'),
        ];
    }
}
