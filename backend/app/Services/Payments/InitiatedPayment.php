<?php

namespace App\Services\Payments;

final readonly class InitiatedPayment
{
    /**
     * @param  array<string, mixed>  $data  Provider details to keep, e.g. a poll URL.
     * @param  array{type: string, message?: string, url?: string}  $nextAction  What the app should show:
     *                                                                         "approve_on_phone" or "redirect".
     */
    public function __construct(
        public string $reference,
        public array $data,
        public array $nextAction,
    ) {}
}
