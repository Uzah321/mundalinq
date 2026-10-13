<?php

namespace App\Http\Resources;

use App\Models\DisputeMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin DisputeMessage
 */
class DisputeMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'party' => $this->author_party,
            'author' => ['name' => Str::before($this->author->name, ' ')],
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
