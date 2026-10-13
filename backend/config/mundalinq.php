<?php

return [

    /*
    | Country code applied to local phone numbers such as 0771234567.
    | The pilot market is Zimbabwe (+263).
    */
    'default_country_code' => env('MUNDALINQ_DEFAULT_COUNTRY_CODE', '263'),

    'auth' => [
        // Lifetime of a mobile device's API token before the user must log in again.
        'token_ttl_days' => (int) env('MUNDALINQ_TOKEN_TTL_DAYS', 90),
        // Staff (admins, support) confirm every sign-in with an SMS code.
        'staff_two_factor' => (bool) env('MUNDALINQ_STAFF_TWO_FACTOR', true),
        'two_factor_challenge_minutes' => 10,
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('MUNDALINQ_OTP_TTL_MINUTES', 10),
        'max_attempts' => 5,
    ],

    'sms' => [
        // "log" writes messages to the application log; only suitable for local development.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'farms' => [
        // Private disk for identity and business documents. Files are encrypted before storage.
        'verification_disk' => env('MUNDALINQ_VERIFICATION_DISK', 'local'),
        'max_document_kb' => 5120,
        'max_locations' => 10,
    ],

    'catalog' => [
        // Off: a verified farm's listings go on sale as soon as it publishes them. On: an admin reviews
        // every listing first. Categories marked requires_approval (livestock, dairy) are always reviewed.
        'require_product_approval' => (bool) env('MUNDALINQ_REQUIRE_PRODUCT_APPROVAL', false),
        // Listings expire so buyers never see stale produce; farmers renew them in one tap.
        'listing_ttl_days' => (int) env('MUNDALINQ_LISTING_TTL_DAYS', 30),
        'max_variants' => 10,
        'max_price_tiers' => 5,
        'max_images' => 8,
        'image_disk' => env('MUNDALINQ_IMAGE_DISK', 'public'),
        'image_max_kb' => 10240,
        // The app resizes photos before upload; this guards server memory against full-size originals.
        'image_max_megapixels' => 16,
        'image_sizes' => [
            'large' => 1600,
            'thumb' => 480,
        ],
    ],

    'orders' => [
        // Farms must accept or reject a new order within this time; otherwise it expires and the stock is freed.
        'confirm_within_minutes' => (int) env('MUNDALINQ_ORDER_CONFIRM_MINUTES', 240),
        // Once every farm has answered, the buyer has this long to pay before the order is cancelled.
        'pay_within_minutes' => (int) env('MUNDALINQ_ORDER_PAY_MINUTES', 180),
        // After handover the buyer can report a problem for this long; then the farm's earnings are final.
        'dispute_window_hours' => (int) env('MUNDALINQ_DISPUTE_WINDOW_HOURS', 48),
        'max_cart_items' => 30,
    ],

    'disputes' => [
        // Private disk for evidence photos; they are only ever served through the API.
        'evidence_disk' => env('MUNDALINQ_EVIDENCE_DISK', 'local'),
        'max_evidence' => 6,
        'evidence_max_edge' => 1600,
    ],

    'reviews' => [
        // Buyers can review a farm order for this long after it is completed.
        'window_days' => (int) env('MUNDALINQ_REVIEW_WINDOW_DAYS', 30),
    ],

    'payments' => [
        // "sandbox" simulates a mobile money provider and never runs in production.
        'provider' => env('PAYMENT_PROVIDER', 'sandbox'),
        // A payment prompt left unanswered this long is treated as abandoned.
        'attempt_minutes' => 15,
        'sandbox' => [
            'webhook_secret' => env('SANDBOX_WEBHOOK_SECRET', 'sandbox-webhook-secret'),
            'delayed_success_seconds' => 30,
            'refunds_fail' => (bool) env('SANDBOX_REFUNDS_FAIL', false),
        ],
    ],

    'payouts' => [
        // After a farm changes where its money goes, payouts wait this long (and every owner is alerted).
        'account_change_hold_hours' => (int) env('MUNDALINQ_PAYOUT_HOLD_HOURS', 24),
    ],

    'commission' => [
        // Platform fee on the goods value of each sale (not on the farm's delivery fee). "0.05" = 5%.
        'rate' => env('MUNDALINQ_COMMISSION_RATE', '0.05'),
    ],

    // The market whose rules (currency, timezone, regions) apply until multi-country support is needed.
    'default_country' => 'ZW',

    // Supported markets and their first-level regions, keyed by ISO country code.
    'countries' => [
        'ZW' => [
            'name' => 'Zimbabwe',
            'timezone' => 'Africa/Harare',
            'currency' => 'USD',
            'provinces' => [
                'Bulawayo',
                'Harare',
                'Manicaland',
                'Mashonaland Central',
                'Mashonaland East',
                'Mashonaland West',
                'Masvingo',
                'Matabeleland North',
                'Matabeleland South',
                'Midlands',
            ],
        ],
    ],

];
