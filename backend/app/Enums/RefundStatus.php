<?php

namespace App\Enums;

enum RefundStatus: string
{
    /** Recorded and owed; waiting for the provider. */
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    /** The provider refused or could not be reached. Still owed: retry it or pay it out by hand. */
    case Failed = 'failed';
}
