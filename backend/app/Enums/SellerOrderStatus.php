<?php

namespace App\Enums;

/**
 * One farm's part of an order:
 *
 *   awaiting_confirmation -> accepted -> preparing -> ready -> (dispatched) -> delivered -> completed
 *            \-> rejected / expired / cancelled
 */
enum SellerOrderStatus: string
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** The farm did not answer in time. */
    case Expired = 'expired';

    /** Cancelled before payment: by the buyer, or because the order was never paid. */
    case Cancelled = 'cancelled';

    /** Paid; the farm is getting it ready. */
    case Preparing = 'preparing';

    /** Packed: waiting for collection (pickup) or dispatch (delivery). */
    case Ready = 'ready';

    case Dispatched = 'dispatched';

    /** Handed over, confirmed with the buyer's code. */
    case Delivered = 'delivered';

    /** The buyer reported a problem within the dispute window; earnings wait for support's decision. */
    case Disputed = 'disputed';

    /** Final: the farm's earnings can be paid out. */
    case Completed = 'completed';

    /**
     * Statuses of a farm order that has been paid for: being, or having been, fulfilled.
     *
     * @return list<self>
     */
    public static function paid(): array
    {
        return [self::Preparing, self::Ready, self::Dispatched, self::Delivered, self::Disputed, self::Completed];
    }

    /**
     * Its stock is still reserved: not yet handed over, and not given back.
     */
    public function holdsStock(): bool
    {
        return in_array($this, [self::AwaitingConfirmation, self::Accepted, self::Preparing, self::Ready], true);
    }

    /**
     * The farm will not supply this part of the order.
     */
    public function isDropped(): bool
    {
        return in_array($this, [self::Rejected, self::Expired, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AwaitingConfirmation => [self::Accepted, self::Rejected, self::Expired, self::Cancelled],
            self::Accepted => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready],
            self::Ready => [self::Dispatched, self::Delivered],
            self::Dispatched => [self::Delivered],
            self::Delivered => [self::Completed, self::Disputed],
            self::Disputed => [self::Completed],
            self::Rejected, self::Expired, self::Cancelled, self::Completed => [],
        };
    }

    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Accepted => 'accepted_at',
            self::Rejected => 'rejected_at',
            self::Expired, self::Cancelled => 'cancelled_at',
            self::Ready => 'ready_at',
            self::Dispatched => 'dispatched_at',
            self::Delivered => 'delivered_at',
            self::Completed => 'completed_at',
            default => null,
        };
    }
}
