<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Python worker (internal)
    |--------------------------------------------------------------------------
    |
    | Shared HMAC secret used to authenticate requests from the Python worker
    | to the internal ingestion API. Must match WORKER_INTERNAL_KEY on the
    | worker side. The internal endpoints reject all requests if this is unset
    | or still the placeholder value.
    |
    */

    'worker' => [
        'url' => env('WORKER_URL', 'http://localhost:8001'),
        'internal_key' => env('WORKER_INTERNAL_KEY'),
        // Reject worker requests whose timestamp drifts more than this many
        // seconds from server time (replay-attack protection).
        'max_clock_skew' => (int) env('WORKER_MAX_CLOCK_SKEW', 300),
    ],

];
