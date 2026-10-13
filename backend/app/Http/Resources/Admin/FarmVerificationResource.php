<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\FarmResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reviewer's view of a submission, including the full identity details.
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
            'id_number' => $this->id_number,
            'business_registration_number' => $this->business_registration_number,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'farm' => FarmResource::make($this->whenLoaded('farm')),
            'submitted_by' => UserResource::make($this->whenLoaded('submitter')),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'download_url' => route('v1.admin.farm-verifications.documents.show', [$this->id, $document->id]),
            ])),
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
