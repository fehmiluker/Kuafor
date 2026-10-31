<?php

namespace App\Services\Booking;

/** Bookings to ignore while checking a slot (the appointment being moved/approved, its own holds). */
final class Exclusions
{
    /** @param list<int> $holdIds */
    public function __construct(public readonly ?int $appointmentId = null, public readonly array $holdIds = []) {}

    public static function none(): self
    {
        return new self;
    }
}
