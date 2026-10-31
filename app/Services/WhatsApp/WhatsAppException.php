<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** Provider error with Meta's error code; `retryable` drives job retry/backoff. */
class WhatsAppException extends RuntimeException
{
    // https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
    public const RETRYABLE_CODES = ['1', '2', '4', '80007', '130429', '131000', '131016', '131048', '131056', 'timeout'];

    public const AUTH_CODES = ['190', '0', '3', '10', '200', '131005'];

    public const OUTSIDE_WINDOW_CODES = ['131047', '131026'];

    public function __construct(string $message, public readonly string $errorCode = 'unknown', public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true) || ($this->httpStatus !== null && $this->httpStatus >= 500);
    }

    public function isAuthError(): bool
    {
        return in_array($this->errorCode, self::AUTH_CODES, true) || $this->httpStatus === 401;
    }

    public function isOutsideServiceWindow(): bool
    {
        return in_array($this->errorCode, self::OUTSIDE_WINDOW_CODES, true);
    }
}
