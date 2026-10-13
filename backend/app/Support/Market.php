<?php

namespace App\Support;

/**
 * Rules of the market the platform currently serves (see config mundalinq.default_country).
 */
final class Market
{
    public static function countryCode(): string
    {
        return config('mundalinq.default_country');
    }

    public static function currency(): string
    {
        return config('mundalinq.countries.'.self::countryCode().'.currency');
    }

    public static function timezone(): string
    {
        return config('mundalinq.countries.'.self::countryCode().'.timezone');
    }

    /**
     * Today's date where the farmers are. Timestamps are stored in UTC, but a harvest
     * "today" in Harare must not be rejected as a future date at 01:00 local time.
     */
    public static function today(): string
    {
        return now(self::timezone())->toDateString();
    }
}
