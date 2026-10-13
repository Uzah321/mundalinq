<?php

namespace App\Models;

use App\Enums\ConversationSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Written only by MessagingService.
 */
#[Fillable(['buyer_id', 'farm_id'])]
class Conversation extends Model
{
    use HasUuids;

    /** Microseconds: unread counts compare message times with read markers. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'buyer_last_read_at' => 'datetime',
            'farm_last_read_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id')->withTrashed();
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class)->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Adds last_message_body and last_message_side for inbox previews. (latestOfMany() would take
     * MAX(id), which PostgreSQL does not define for UUIDs.)
     */
    public function scopeWithLastMessage(Builder $query): void
    {
        $latest = fn (string $column) => Message::select($column)
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $query->addSelect([
            'last_message_body' => $latest('body'),
            'last_message_side' => $latest('sender_side'),
        ]);
    }

    /**
     * The side a user is on, or null when they take no part (staff only read).
     */
    public function sideOf(User $user): ?ConversationSide
    {
        return match (true) {
            $this->buyer_id === $user->id => ConversationSide::Buyer,
            $this->farm->memberRole($user) !== null => ConversationSide::Farm,
            default => null,
        };
    }

    /**
     * Adds unread_count: messages from the other side since this side last read.
     */
    public function scopeWithUnreadCountFor(Builder $query, ConversationSide $side): void
    {
        $query->withCount(['messages as unread_count' => fn (Builder $messages) => $messages
            ->where('sender_side', $side->other())
            ->where(fn (Builder $unread) => $unread
                ->whereNull('conversations.'.$side->readColumn())
                ->orWhereColumn('messages.created_at', '>', 'conversations.'.$side->readColumn())),
        ]);
    }
}
