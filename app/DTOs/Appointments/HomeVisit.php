<?php

namespace App\DTOs\Appointments;

/** Customer's address for a home/workplace visit ("Adrese hizmet"). */
final class HomeVisit
{
    public function __construct(
        public readonly int $districtId,
        public readonly string $addressLine,
        public readonly ?string $addressDetail = null,
        public readonly string $placeType = 'home',
    ) {}
}
