<?php

namespace App\Enums;

enum PayoutMethod: string
{
    /** EcoCash, OneMoney and similar wallets, identified by a phone number. */
    case MobileMoney = 'mobile_money';
}
