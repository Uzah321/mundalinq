<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A webhook that cannot be proven to come from the payment provider.
 */
class InvalidWebhookException extends RuntimeException {}
