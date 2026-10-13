<?php

namespace App\Contracts;

/**
 * Sends alerts by WhatsApp to people who chose it over SMS.
 */
interface WhatsAppGateway
{
    /**
     * @throws \Throwable when the message could not be sent; callers fall back to SMS.
     */
    public function send(string $phone, string $message): void;
}
