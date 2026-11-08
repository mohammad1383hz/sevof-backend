<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web', 'horizon.session'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    */

    'waits' => [
        'redis:default' => 60,
        'redis-background:background' => 1800,
        'redis-background:products' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times (minutes)
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB) - master supervisor
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        // هم‌نام supervisor-default تا فقط تنظیمات پایه‌اش باشد؛ با اسم supervisor-1 یک worker اضافه با timeout ۶۰ و tries ۱ روی default اجرا می‌شد
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

 'environments' => [
    'production' => [
        // کارهای کاربران. minProcesses همیشه بیکار آماده است، حتی وقتی محصولات و ایجنت مشغول‌اند.
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            // Worker counts come from .env so a small shared server can run fewer processes.
            'minProcesses' => (int) env('HORIZON_DEFAULT_MIN', 3),
            'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX', 6),
            'tries' => 3,
            'timeout' => 120,
            'memory' => 256,
        ],

        // کارهای زمان‌بندی‌شده (ایجنت، تحقیق کلمات، سرچ کنسول، آپدیت خودکار مقاله): جدا از default
        // تا وقتی این کارها شروع می‌شوند workerهای کاربران آزاد بمانند.
        'supervisor-background' => [
            'connection' => 'redis-background',
            'queue' => ['background'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => (int) env('HORIZON_BACKGROUND_MAX', 2),
            'tries' => 2,
            'timeout' => 900,
            'memory' => 256,
            'maxJobs' => 200,   // ریستارت پروسس برای جلوگیری از memory leak
        ],

        // آپدیت و همگام‌سازی محصولات (ساعت ۳ و ثبت سایت): صف و worker کاملاً جدا
        'supervisor-products' => [
            'connection' => 'redis-background',
            'queue' => ['products'],
            'balance' => 'simple',
            'processes' => (int) env('HORIZON_PRODUCTS_PROCESSES', 4),
            'tries' => 2,
            'timeout' => 900,
            'memory' => 256,
            'maxJobs' => 200,
        ],
    ],
],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
