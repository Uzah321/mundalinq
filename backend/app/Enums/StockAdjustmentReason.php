<?php

namespace App\Enums;

enum StockAdjustmentReason: string
{
    case CountCorrection = 'count_correction';
    case Spoilage = 'spoilage';
    case Damage = 'damage';
    case Theft = 'theft';
    case OwnUse = 'own_use';
    case Other = 'other';

    /**
     * Losses can only ever take stock away.
     */
    public function onlyDecreases(): bool
    {
        return in_array($this, [self::Spoilage, self::Damage, self::Theft, self::OwnUse], true);
    }
}
