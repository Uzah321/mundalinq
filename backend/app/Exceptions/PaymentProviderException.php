<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment provider could not take or answer a request. $buyerMessage, when set, tells the buyer
 * why in words they can act on (e.g. an unsupported network); otherwise they are asked to try again.
 */
class PaymentProviderException extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?string $buyerMessage = null)
    {
        parent::__construct($message);
    }
}
