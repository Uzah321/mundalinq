<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * Something a user should know about: stored in their in-app inbox and, for moments where someone
 * has to act, also sent by SMS unless they turned SMS off for the category.
 *
 * Sent synchronously, like the SMS it replaces; send it after the transaction commits.
 */
class MarketplaceAlert extends Notification
{
    /**
     * @param  array<string, string|null>  $context  Ids the app uses to open the right screen, e.g. order_id.
     * @param  bool  $sms  Also send an SMS: the body, or $smsText when the body needs the app around it.
     */
    public function __construct(
        public NotificationCategory $category,
        public string $title,
        public string $body,
        public array $context = [],
        public bool $sms = false,
        public ?string $smsText = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->sms && $notifiable->wantsSms($this->category)
            ? ['database', SmsChannel::class]
            : ['database'];
    }

    public function databaseType(User $notifiable): string
    {
        return $this->category->value;
    }

    /**
     * @return array{title: string, body: string, context: array<string, string|null>}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'context' => $this->context];
    }

    public function toSms(): string
    {
        return $this->smsText ?? $this->body;
    }
}
