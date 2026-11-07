<?php

/**
 * قیمت هر مدل به ازای هر ۱٬۰۰۰٬۰۰۰ توکن (دلار آمریکا).
 * این تنها منبع معتبر قیمت‌گذاری است — TokenUsageService دقیقاً از همین
 * جدول برای محاسبه‌ی cost_usd استفاده می‌کند.
 *
 * نکته: کلید باید دقیقاً همان مقداری باشد که در ستون `model` جدول
 * token_usage_records ذخیره می‌شود (یعنی همان چیزی که به API پاس داده شده).
 */


return [
    'models' => [
        'gpt-4.1-mini' => [
            'input_per_million' => 0.40,
            'output_per_million' => 1.60,
        ],
        'gpt-5-mini' => [
            'input_per_million' => 0.25,
            'output_per_million' => 2.00,
        ],
        'gpt-5-nano' => [
            'input_per_million' => 0.050,
            'output_per_million' => 0.40,
        ],
        'gpt-5.4-mini' => [
            'input_per_million' => 0.75,
            'output_per_million' => 4.50,
        ],
        'gpt-5.6-luna' => [
            'input_per_million' => 1.00,
            'output_per_million' => 6.00,
        ],
        'gpt-4o-mini' => [
            'input_per_million' => 0.15,
            'output_per_million' => 0.60,
        ],
    ],
];
