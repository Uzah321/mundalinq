<?php

namespace App\Enums;

enum FarmerType: string
{
    case Smallholder = 'smallholder';
    case Commercial = 'commercial';
    case Cooperative = 'cooperative';
    case SupplyAgent = 'supply_agent';

    /**
     * Registered businesses must also prove their registration during verification.
     */
    public function requiresBusinessRegistration(): bool
    {
        return in_array($this, [self::Commercial, self::Cooperative], true);
    }
}
