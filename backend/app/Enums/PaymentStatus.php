<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Nobody approved it in time. The provider can still report a late success, which is then honoured. */
    case Expired = 'expired';

    /**
     * The provider has given its final answer.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
