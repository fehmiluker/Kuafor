<?php

namespace App\Services\Booking;

use App\Support\Money;

/** One requested service, resolved for a specific branch (+ optional employee override). */
final class ServiceLine
{
    public function __construct(
        public readonly int $serviceId,
        public readonly string $name,
        public readonly int $durationMinutes,
        public readonly int $bufferMinutes,
        public readonly Money $price,
    ) {}
}
