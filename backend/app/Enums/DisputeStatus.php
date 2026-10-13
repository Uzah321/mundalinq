<?php

namespace App\Enums;

enum DisputeStatus: string
{
    /** Waiting for a decision from support; the farm's earnings for the order are on hold. */
    case Open = 'open';

    case Resolved = 'resolved';

    /** The buyer settled it themselves; the sale completed as normal. */
    case Withdrawn = 'withdrawn';
}
