<?php

return [
    'python_bin'   => env('KWSCRAPER_VENV_PYTHON', env('KWSCRAPER_PYTHON_BIN', 'python3')),
    'project_path' => env('KWSCRAPER_PROJECT_PATH', base_path('python/seo-keyword-scraper')),
    'lang'         => env('KWSCRAPER_LANG', 'en'),
    'country'      => env('KWSCRAPER_COUNTRY', 'IR'),
    'timeout'      => (int) env('KWSCRAPER_TIMEOUT', 30),
];
