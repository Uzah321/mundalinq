<?php

namespace App\Enums;

enum StockMovementType: string
{
    /** New stock arrived: a harvest or delivery batch. */
    case Receipt = 'receipt';

    /** A manual correction or loss recorded by the farmer (see StockAdjustmentReason). */
    case Adjustment = 'adjustment';

    /** Stock leaving with a fulfilled order. */
    case Sale = 'sale';
}
