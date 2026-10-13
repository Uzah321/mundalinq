<?php

namespace App\Enums;

enum PaymentMethod: string
{
    /** EcoCash, OneMoney and similar: the buyer approves a prompt on their phone. */
    case MobileMoney = 'mobile_money';
}
