<?php

return [

    'request_timeout' => env('WP_AGENT_REQUEST_TIMEOUT', 20),


    'plugin_routes' => [
        'health' => '/index.php?rest_route=/sevof-connector/v1/health',
        'articles' => '/index.php?rest_route=/sevof-connector/v1/articles',
        'products' => '/index.php?rest_route=/sevof-connector/v1/products',
    ],

    // Clean REST route (what WordPress's get_route() returns); used only for signing.
    'signing_paths' => [
        'health' => 'sevof-connector/v1/health',
        'articles' => 'sevof-connector/v1/articles',
        'products' => 'sevof-connector/v1/products',
    ],
    'timestamp_tolerance_seconds' => env(
        'WP_AGENT_TIMESTAMP_TOLERANCE',
        300
    ),

    'rate_limit_max_attempts' => env(
        'WP_AGENT_RATE_LIMIT_MAX_ATTEMPTS',
        60
    ),

    'rate_limit_decay_seconds' => env(
        'WP_AGENT_RATE_LIMIT_DECAY_SECONDS',
        60
    ),

];
