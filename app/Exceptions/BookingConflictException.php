<?php

namespace App\Exceptions;

use RuntimeException;

/** The requested slot is no longer available (taken, held, outside hours, leave...). */
class BookingConflictException extends RuntimeException
{
    public function __construct(string $message = 'Seçilen saat artık müsait değil.', public readonly string $reason = 'slot_unavailable')
    {
        parent::__construct($message);
    }
}
