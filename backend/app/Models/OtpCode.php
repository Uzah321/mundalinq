<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['identifier', 'purpose', 'code_hash', 'expires_at', 'consumed_at'])]
class OtpCode extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Unused, unexpired codes for an identifier and purpose.
     */
    public function scopeActive(Builder $query, string $identifier, OtpPurpose $purpose): void
    {
        $query->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }
}
