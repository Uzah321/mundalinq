<?php

namespace App\Enums;

enum DisputeReason: string
{
    case MissingItems = 'missing_items';
    case Damaged = 'damaged';
    case PoorQuality = 'poor_quality';
    case WrongItems = 'wrong_items';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MissingItems => __('items were missing'),
            self::Damaged => __('goods arrived damaged'),
            self::PoorQuality => __('quality was poor'),
            self::WrongItems => __('wrong items were supplied'),
            self::Other => __('another problem'),
        };
    }
}
