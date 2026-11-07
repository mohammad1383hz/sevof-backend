<?php

namespace App\Services\Shopify;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, public readonly mixed $body = null)
    {
        parent::__construct($message, $statusCode);
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isUnauthorized(): bool
    {
        return in_array($this->statusCode, [401, 403], true);
    }
}
