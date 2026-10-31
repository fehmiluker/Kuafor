<?php

namespace App\Exceptions;

use RuntimeException;

/** Business-rule violation shown to the user (HTTP 422). */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation')
    {
        parent::__construct($message);
    }
}
