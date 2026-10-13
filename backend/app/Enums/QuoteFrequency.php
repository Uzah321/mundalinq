<?php

namespace App\Enums;

enum QuoteFrequency: string
{
    case OneOff = 'one_off';
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::OneOff => __('once'),
            self::Weekly => __('every week'),
            self::Fortnightly => __('every two weeks'),
            self::Monthly => __('every month'),
        };
    }
}
