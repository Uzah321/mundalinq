<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use Throwable;

/**
 * Alerts for people who chose WhatsApp over SMS. When WhatsApp cannot take the message it goes by SMS
 * instead, so nobody misses an order.
 */
class WhatsAppChannel
{
    public function __construct(private WhatsAppGateway $whatsApp, private SmsGateway $sms) {}

    public function send(User $notifiable, MarketplaceAlert $notification): void
    {
        if (! $notifiable->phone) {
            return;
        }

        try {
            $this->whatsApp->send($notifiable->phone, $notification->toSms());
        } catch (Throwable $e) {
            report($e);
            $this->sms->send($notifiable->phone, $notification->toSms());
        }
    }
}
