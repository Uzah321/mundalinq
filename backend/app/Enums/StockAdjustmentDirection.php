<?php

namespace App\Enums;

enum StockAdjustmentDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
}
