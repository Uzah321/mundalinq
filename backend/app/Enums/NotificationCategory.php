<?php

namespace App\Enums;

/**
 * What a notification is about. Every notification lands in the in-app inbox; users can turn
 * off the SMS copy per category (see User::wantsSms). Security codes are not notifications
 * and are always sent.
 */
enum NotificationCategory: string
{
    case Orders = 'orders';
    case Messages = 'messages';
    case Reviews = 'reviews';
    case Disputes = 'disputes';

    /** Listing moderation and low-stock alerts. */
    case Listings = 'listings';

    /** Farm verification and account changes. */
    case Account = 'account';

    case Payouts = 'payouts';

    /** Requests for quotes and the offers farms send. */
    case Quotes = 'quotes';

    /**
     * Categories that are ever sent by SMS, and so have an SMS setting.
     *
     * @return list<self>
     */
    public static function withSms(): array
    {
        return array_values(array_filter(self::cases(), fn (self $category) => $category !== self::Reviews));
    }
}
