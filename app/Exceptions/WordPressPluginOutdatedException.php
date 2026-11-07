<?php

namespace App\Exceptions;

use RuntimeException;

/** افزونه‌ی سایت هنوز endpoint لازم را ندارد و باید به‌روز شود. */
class WordPressPluginOutdatedException extends RuntimeException
{
    public function __construct(string $minVersion)
    {
        parent::__construct("To update past articles, update the Sevof plugin on your site to version {$minVersion} or later.");
    }
}
