<?php

namespace App\Services\Payments;

final readonly class WebhookNotification
{
    /**
     * @param  string  $eventId  The provider's id for this delivery, used to ignore replays.
     * @param  string  $reference  The provider's reference for the payment.
     */
    public function __construct(
        public string $eventId,
        public string $reference,
        public array $payload,
    ) {}
}
