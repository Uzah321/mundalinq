<?php

namespace App\Support;

use App\Models\Order;
use RuntimeException;

/**
 * Short order numbers people can read over the phone: "ML-7K3Q9P". No 0/O or 1/I to confuse.
 */
final class OrderNumber
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'ML-';

            for ($i = 0; $i < 6; $i++) {
                $number .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            if (! Order::where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not generate a unique order number.');
    }
}
