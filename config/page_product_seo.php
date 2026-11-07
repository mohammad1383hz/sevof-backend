<?php

return [
    // مدل‌ها حتماً باید در TokenUsageService::MODEL_PRICING باشند، وگرنه هزینه/کریدیت صفر ثبت می‌شود.
    'ai' => [
        'classifier_model' => env('PAGE_PRODUCT_SEO_CLASSIFIER_MODEL', 'gpt-4o-mini'),
        'generator_model' => env('PAGE_PRODUCT_SEO_GENERATOR_MODEL', 'gpt-4.1-mini'),
    ],

'search' => [
    'enabled' => (bool) env('PAGE_PRODUCT_SEO_SEARCH_ENABLED', false),
    'max_results' => 10,
    'max_pages' => 3,
],
    'classify_chunk' => 50,   // تعداد محصول در هر درخواست تشخیص true/false
    'max_products' => 100,    // سقف محصول در هر درخواست
];
