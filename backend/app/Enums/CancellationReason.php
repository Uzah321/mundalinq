<?php

namespace App\Enums;

enum CancellationReason: string
{
    case BuyerCancelled = 'buyer_cancelled';

    /** No farm accepted its part in time. */
    case NotConfirmed = 'not_confirmed';

    case PaymentOverdue = 'payment_overdue';

    public function message(): string
    {
        return match ($this) {
            self::BuyerCancelled => __('you cancelled it.'),
            self::NotConfirmed => __('the farms could not supply it.'),
            self::PaymentOverdue => __('it was not paid in time.'),
        };
    }
}
