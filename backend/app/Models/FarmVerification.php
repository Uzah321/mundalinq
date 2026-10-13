<?php

namespace App\Models;

use App\Enums\FarmVerificationStatus;
use App\Enums\IdDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'id_document_type', 'id_number', 'business_registration_number', 'notes'])]
#[Hidden(['id_number', 'business_registration_number'])]
class FarmVerification extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => FarmVerificationStatus::class,
            'id_document_type' => IdDocumentType::class,
            'id_number' => 'encrypted',
            'business_registration_number' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class)->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FarmVerificationDocument::class);
    }

    public function isPending(): bool
    {
        return $this->status === FarmVerificationStatus::Pending;
    }
}
