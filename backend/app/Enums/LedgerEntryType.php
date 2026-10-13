<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    /** The farm's share of a paid order: goods plus its delivery fee. */
    case Sale = 'sale';

    /** The platform's fee (negative). */
    case Commission = 'commission';

    case ProviderFee = 'provider_fee';
    case Adjustment = 'adjustment';

    /** Money given back to the buyer after a dispute (negative). */
    case Refund = 'refund';

    /** Money sent to the farm (negative): it leaves the available balance. */
    case Payout = 'payout';

    /** A farm paying to feature one of its listings. */
    case Promotion = 'promotion';
}
