<?php

namespace App\Exceptions;

use RuntimeException;

/** سهمیه‌ی روزانه/دقیقه‌ای URL Inspection سرچ کنسول برای این property تمام شده است. */
class SearchConsoleQuotaException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This site\'s Search Console URL inspection quota is used up for now.');
    }
}
