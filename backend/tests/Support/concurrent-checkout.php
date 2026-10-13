<?php

/*
 * One checkout in its own PHP process, for ConcurrentCheckoutTest.
 * Usage: php concurrent-checkout.php <buyer-id> <start-at (unix time, float)>
 * Prints "placed" or "refused".
 */

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\QuoteBuilder;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $buyerId, $startAt] = $argv;

$buyer = User::findOrFail($buyerId);
$quote = app(QuoteBuilder::class)->build(app(CartService::class)->for($buyer), $buyer, true);
$total = Money::format($quote->totalMinor());

// Wait so every process reaches the critical section together.
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    app(CheckoutService::class)->placeOrder($buyer, null, [], $total);
    echo 'placed';
} catch (BusinessRuleException) {
    echo 'refused';
}
