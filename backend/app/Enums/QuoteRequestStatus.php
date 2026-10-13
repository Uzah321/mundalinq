<?php

namespace App\Enums;

enum QuoteRequestStatus: string
{
    /** Taking offers until closes_at. */
    case Open = 'open';

    /** The buyer accepted an offer. */
    case Awarded = 'awarded';

    /** The buyer stopped it without accepting anything. */
    case Closed = 'closed';
}
