<?php

namespace App\Exceptions;

use RuntimeException;

class PlanLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly string $limitKey = '')
    {
        parent::__construct($message);
    }
}
