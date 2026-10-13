<?php

namespace App\Enums;

/**
 * Who is speaking in a dispute.
 */
enum DisputeParty: string
{
    case Buyer = 'buyer';
    case Farm = 'farm';
    case Staff = 'staff';
}
