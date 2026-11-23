<?php

declare(strict_types=1);

return [

    /*
    | Inbound lead capture (R12.1-R12.3). One shared secret per lead source;
    | the sender signs "{timestamp}.{raw body}" with HMAC-SHA256.
    | Only sources listed here (with a non-empty secret) are accepted.
    */
    'inbound' => [
        'sources' => array_filter([
            'website' => env('WEBHOOK_SECRET_WEBSITE'),
            'facebook' => env('WEBHOOK_SECRET_FACEBOOK'),
            'google_ads' => env('WEBHOOK_SECRET_GOOGLE_ADS'),
        ]),
        // Requests older/newer than this many seconds are rejected (replay protection).
        'timestamp_tolerance' => (int) env('WEBHOOK_TIMESTAMP_TOLERANCE', 300),
    ],

    /*
    | Outbound event notifications (R12.4-R12.9).
    */
    'outbound' => [
        // Only for local development: allow http:// and private/loopback hosts.
        'allow_private_urls' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_URLS', false),
        'timeout' => 10,
        'tries' => 6,
        'backoff' => [10, 30, 120, 600, 1800],
    ],

];
