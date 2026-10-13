<?php

namespace App\Enums;

/**
 * A payment provider's answer, before we apply it to our own records.
 */
enum ProviderState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
