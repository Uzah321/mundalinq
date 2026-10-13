<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsGateway;
use App\Models\User;
use App\Notifications\MarketplaceAlert;

class SmsChannel
{
    public function __construct(private SmsGateway $sms) {}

    public function send(User $notifiable, MarketplaceAlert $notification): void
    {
        if ($notifiable->phone) {
            $this->sms->send($notifiable->phone, $notification->toSms());
        }
    }
}
