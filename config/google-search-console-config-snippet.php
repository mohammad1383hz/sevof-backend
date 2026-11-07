<?php

// این بخش را به آرایه برگشتی config/seobot.php پروژه اضافه کنید.
return [
    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),

    'google' => [
        // تنظیم موجود برای ورود با Google را بدون تغییر نگه دارید.
        'client_id' => env('GOOGLE_CLIENT_ID'),

        // OAuth Client نوع Web application برای Search Console.
        'search_console_client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
        'search_console_client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),
        'search_console_redirect_uri' => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI'),
    ],
];
