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
        'token' => env('POSTMARK_TOKEN'),
    ],

'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'grad' => [
        'base_url' => env('GRAD_BASE_INQ'),
        'api_token' => env('TOKEN_BASE'),
        'base_url_stats_inq' => env('GRAD_BASE_STATS_INQ'),



    ],
'manus' => [
    'api_key' => env('MANUS_API_KEY'),
    'base_url' => env('MANUS_API_BASE', 'https://api.manus.ai' ),
    'agent_profile' => env('MANUS_AGENT_PROFILE', 'manus-1.6-max'),
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
        'gradcard' => [
    'url' => env('GRADCARD_URL','https://api.inq.tagfa.iau.ir/gradcard/check'),
    'key' => env('GRADCARD_KEY','dcc6c55f-cc33-11f0-8327-0ede983d923b'),
],

'google_autocomplete' => [
    'base_url' => env('GOOGLE_AUTOCOMPLETE_URL', 'https://suggestqueries.google.com/complete/search'),
    'timeout' => env('GOOGLE_AUTOCOMPLETE_TIMEOUT', 10),
],
'just_serp' => [
    'base_url' => env('just_serp_base_url', 'https://api.justserpapi.com/api/v1'),
    'key' => env('JUSTSERP_API_KEY'),
],

// config/services.php
'shortapi' => [
    'base_url' => 'https://api.shortapi.ai/api/v1',
    'key' => 'ak-234f07f48eef11f1bc89dace681ecb39',
],
];
