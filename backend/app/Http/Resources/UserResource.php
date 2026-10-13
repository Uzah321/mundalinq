<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'phone_verified' => $this->hasVerifiedPhone(),
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'status' => $this->status,
            'locale' => $this->locale,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
