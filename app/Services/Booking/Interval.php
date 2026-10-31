<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;

/** Half-open UTC interval [start, end). */
final class Interval
{
    public function __construct(public readonly CarbonImmutable $start, public readonly CarbonImmutable $end, public readonly string $kind = '') {}

    /** existing.start < requested.end AND existing.end > requested.start */
    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->start->lt($end) && $this->end->gt($start);
    }

    public function contains(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->start->lte($start) && $this->end->gte($end);
    }
}
