<?php
// app/Exceptions/InsufficientCreditException.php

namespace App\Exceptions;

use Exception;

class InsufficientCreditException extends Exception
{
    public function __construct(string $message = 'Not enough plan credit to continue.')
    {
        parent::__construct($message);
    }
}
