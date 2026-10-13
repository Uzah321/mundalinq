<?php

namespace App\Enums;

enum UnitMeasure: string
{
    case Mass = 'mass';
    case Volume = 'volume';

    /** Heads, bunches, crates, animals... Never converted automatically. */
    case Count = 'count';
}
