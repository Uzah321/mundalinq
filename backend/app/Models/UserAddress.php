<?php

namespace App\Models;

use Database\Factories\UserAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'label', 'recipient_name', 'phone', 'province', 'district', 'ward', 'address_line', 'directions',
    'latitude', 'longitude', 'is_default',
])]
class UserAddress extends Model
{
    /** @use HasFactory<UserAddressFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Frozen copy kept on orders, so editing an address never changes where past orders went.
     */
    public function snapshot(): array
    {
        return $this->only([
            'label', 'recipient_name', 'phone', 'province', 'district', 'ward', 'address_line', 'directions',
            'latitude', 'longitude',
        ]);
    }
}
