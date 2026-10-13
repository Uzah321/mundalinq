<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A thread as one side sees it. Phone numbers are never shared here.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm' => ['id' => $this->farm->id, 'name' => $this->farm->name],
            'buyer' => ['name' => $this->buyer->name],
            // Present in inbox listings (Conversation::withLastMessage).
            'last_message' => $this->when(isset($this->last_message_body), fn () => [
                'body' => Str::limit($this->last_message_body, 140),
                'side' => $this->last_message_side,
            ]),
            'unread_count' => $this->when(isset($this->unread_count), fn () => (int) $this->unread_count),
            'last_message_at' => $this->last_message_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
