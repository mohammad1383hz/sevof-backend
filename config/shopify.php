<?php

/*
| Shopify public app (OAuth). Create it in the Shopify Partner dashboard:
|  - App URL:               {APP_URL}/api/v1/shopify/entry
|  - Allowed redirect URL:  {APP_URL}/api/v1/shopify/callback
|  - Compliance webhooks (customers/data_request, customers/redact, shop/redact):
|                           {APP_URL}/api/shopify/webhooks
*/
return [
    'client_id' => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

    // Blog articles + product SEO + uploading article images to Shopify Files.
    'scopes' => env('SHOPIFY_SCOPES', 'read_content,write_content,read_products,write_products,read_files,write_files'),

    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/shopify/callback'),
    'webhook_uri' => env('SHOPIFY_WEBHOOK_URI', rtrim((string) env('APP_URL'), '/').'/api/shopify/webhooks'),

    // Name of the blog created when the store has none.
    'default_blog_title' => env('SHOPIFY_DEFAULT_BLOG', 'News'),

    'timeout' => 60,
    'image_cache_days' => 30,
    'max_products' => 250,
];
