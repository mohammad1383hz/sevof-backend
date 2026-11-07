<?php

return [
    'crawler' => [
        'max_pages' => (int) env('SEOBOT_CRAWLER_MAX_PAGES', 8),
        'timeout' => (int) env('SEOBOT_CRAWLER_TIMEOUT', 15),
        'user_agent' => env('SEOBOT_CRAWLER_USER_AGENT', 'SeoBot/1.0 (+https://seobot.io)'),
         'max_nav_root_items' => 25,
    'max_nav_children_per_node' => 8,
    'max_nav_deep_children' => 5,
    'max_nav_total_items' => 45,
    'max_extra_urls_per_group' => 3,
    'max_extra_urls_total' => 25,
    'connect_timeout' => 8,
    ],

    // Direct OpenAI (no regional proxy). The `gapgpt` DB table is the key pool for chat calls:
    // insert rows with base_url = https://api.openai.com/v1 and your OpenAI keys.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1/'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],

    // Image generation (OpenAiClient); falls back to the main OpenAI key.
    'openai_image' => [
        'api_key' => env('OPENAI_IMAGE_API_KEY', env('OPENAI_API_KEY')),
    ],

    'openai_analyze' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1/'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],

   'google' => [

    /*
    |--------------------------------------------------------------------------
    | Google OAuth
    |--------------------------------------------------------------------------
    */

    'client_id' => env('GOOGLE_CLIENT_ID'),

    'client_secret' => env('GOOGLE_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Google Search Console OAuth
    |--------------------------------------------------------------------------
    */

    'search_console_client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),

    'search_console_client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),

    'search_console_redirect_uri' => env(
        'GOOGLE_SEARCH_CONSOLE_REDIRECT_URI'
    ),
],
    "justserp" => [
        "api_key" => env('JUSTSERP_API_KEY'),
    ],

    // USD value of one credit (after the AI cost multiplier); plan credit limits use this unit.
    'credit_value_usd' => (float) env('SEVOF_CREDIT_VALUE_USD', 0.01),

    'subscription' => [
        'free_plan_slug' => env('SEOBOT_FREE_PLAN_SLUG', 'free'),
        'free_token_limit' => (int) env('SEOBOT_FREE_TOKEN_LIMIT', 100000),
        'free_site_limit' => (int) env('SEOBOT_FREE_SITE_LIMIT', 1),
        'free_article_limit' => (int) env('SEOBOT_FREE_ARTICLE_LIMIT', 5),
        'default_subscription_days' => (int) env('SEOBOT_DEFAULT_SUBSCRIPTION_DAYS', 30),
        'paid_subscription_days' => (int) env('SEOBOT_PAID_SUBSCRIPTION_DAYS', 30),
    ],
];
