<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * The farmer's view of their own submission: identity numbers are masked.
 *
 * @mixin \App\Models\FarmVerification
 */
class FarmVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'id_document_type' => $this->id_document_type,
            'id_number' => Str::mask($this->id_number, '*', 0, -3),
            'has_business_registration' => $this->business_registration_number !== null,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
            ])),
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
