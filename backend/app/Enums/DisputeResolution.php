<?php

namespace App\Enums;

enum DisputeResolution: string
{
    /** The whole farm order, delivery fee included, goes back to the buyer. */
    case RefundFull = 'refund_full';

    case RefundPartial = 'refund_partial';

    /** The claim is not upheld; the farm keeps the sale. */
    case NoRefund = 'no_refund';
}
