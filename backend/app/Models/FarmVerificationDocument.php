<?php

namespace App\Models;

use App\Enums\VerificationDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'])]
#[Hidden(['disk', 'path'])]
class FarmVerificationDocument extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => VerificationDocumentType::class,
            'size_bytes' => 'integer',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(FarmVerification::class, 'farm_verification_id');
    }
}
