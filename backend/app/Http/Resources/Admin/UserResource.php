<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as staff see it.
 *
 * @mixin User
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
            'status' => $this->status,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
            'farms' => $this->whenLoaded('farms', fn () => $this->farms->map(fn ($farm) => [
                'id' => $farm->id,
                'name' => $farm->name,
                'role' => $farm->pivot->role,
                'verification_status' => $farm->verification_status,
            ])),
            'orders_count' => $this->when(isset($this->orders_count), fn () => (int) $this->orders_count),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
