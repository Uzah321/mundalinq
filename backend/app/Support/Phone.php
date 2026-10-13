<?php

namespace App\Support;

final class Phone
{
    /** E.164: "+" followed by 8 to 15 digits. */
    public const PATTERN = '/^\+[1-9]\d{7,14}$/';

    /**
     * Normalize user input to E.164, e.g. "077 123 4567" -> "+263771234567".
     * Non-string input is returned untouched so validation can reject it.
     */
    public static function normalize(mixed $input): mixed
    {
        if (! is_string($input) || trim($input) === '') {
            return $input;
        }

        $number = preg_replace('/[^\d+]/', '', $input);

        return match (true) {
            str_starts_with($number, '+') => $number,
            str_starts_with($number, '00') => '+'.substr($number, 2),
            str_starts_with($number, '0') => '+'.config('mundalinq.default_country_code').substr($number, 1),
            default => '+'.$number,
        };
    }
}
