<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Writes WhatsApp messages to the application log; only suitable for local development.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $phone, string $message): void
    {
        Log::info('WhatsApp to '.$phone.': '.$message);
    }
}
