<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment provider could not be reached or answered with something unusable.
 */
class PaymentProviderException extends RuntimeException {}
