<?php

namespace App\Http\Resources;

use App\Enums\ConversationSide;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'side' => $this->sender_side,
            // Farm messages show who at the farm wrote them.
            'sender' => ['name' => $this->sender_side === ConversationSide::Farm ? Str::before($this->sender->name, ' ') : $this->sender->name],
            'from_me' => $this->sender_id === $request->user()?->id,
            'product' => $this->product_id ? ['id' => $this->product_id, 'title' => $this->product?->title] : null,
            'seller_order' => $this->seller_order_id ? ['id' => $this->seller_order_id, 'number' => $this->sellerOrder?->number] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
