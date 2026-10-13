<?php

namespace App\Enums;

/**
 * The buyer's order across all farms:
 *
 *   awaiting_confirmation -> awaiting_payment -> paid -> completed
 *            \-------------------\-> cancelled
 */
enum OrderStatus: string
{
    /** Waiting for each farm to accept or reject its part. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Every farm has answered; the buyer pays for what was accepted. */
    case AwaitingPayment = 'awaiting_payment';

    /** Payment confirmed by the provider; farms are fulfilling. */
    case Paid = 'paid';

    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canBeCancelledByBuyer(): bool
    {
        return $this === self::AwaitingConfirmation || $this === self::AwaitingPayment;
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AwaitingConfirmation => [self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Paid => 'paid_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            default => null,
        };
    }
}
