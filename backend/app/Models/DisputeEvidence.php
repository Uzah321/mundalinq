<?php

namespace App\Models;

use App\Enums\DisputeParty;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to a dispute. Served only through the API, to the people in the dispute.
 */
class DisputeEvidence extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'dispute_evidence';

    protected function casts(): array
    {
        return [
            'uploader_party' => DisputeParty::class,
            'size_bytes' => 'integer',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
