<?php

namespace App\Services\Booking;

use App\Support\Money;

/** Duration/price of a set of services for one employee at one branch. */
final class Quote
{
    /** @param list<ServiceLine> $lines */
    public function __construct(
        public readonly int $employeeId,
        public readonly array $lines,
        public readonly int $employeeBufferMinutes,
    ) {}

    public function durationMinutes(): int
    {
        return array_sum(array_map(fn (ServiceLine $l) => $l->durationMinutes, $this->lines));
    }

    /**
     * Preparation buffer after the appointment: the largest service buffer plus the
     * employee's personal buffer (buffers of consecutive services in one visit are
     * not summed — the chair is prepared once). See docs/decisions.md.
     */
    public function bufferMinutes(): int
    {
        $max = 0;
        foreach ($this->lines as $l) {
            $max = max($max, $l->bufferMinutes);
        }

        return $max + $this->employeeBufferMinutes;
    }

    public function subtotal(): Money
    {
        return array_reduce($this->lines, fn (Money $c, ServiceLine $l) => $c->plus($l->price), Money::zero());
    }
}
