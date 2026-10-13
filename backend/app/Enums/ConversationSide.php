<?php

namespace App\Enums;

/**
 * The two sides of a conversation: the buyer, and the farm (any of its members).
 */
enum ConversationSide: string
{
    case Buyer = 'buyer';
    case Farm = 'farm';

    public function other(): self
    {
        return $this === self::Buyer ? self::Farm : self::Buyer;
    }

    /**
     * The conversations column holding this side's read marker.
     */
    public function readColumn(): string
    {
        return $this->value.'_last_read_at';
    }
}
