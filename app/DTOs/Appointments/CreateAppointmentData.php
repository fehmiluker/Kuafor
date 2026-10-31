<?php

namespace App\DTOs\Appointments;

use App\Enums\AppointmentSource;
use Carbon\CarbonImmutable;

final class CreateAppointmentData
{
    /**
     * @param  list<int>  $serviceIds
     * @param  int|null  $employeeId  null = "fark etmez" (any qualified employee)
     */
    public function __construct(
        public readonly int $branchId,
        public readonly array $serviceIds,
        public readonly ?int $employeeId,
        public readonly CarbonImmutable $startsAtUtc,
        public readonly string $customerName,
        public readonly string $customerPhoneE164,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerNote = null,
        public readonly ?int $userId = null,
        public readonly AppointmentSource $source = AppointmentSource::Web,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $byStaff = false,
        public readonly ?int $staffUserId = null,
        public readonly bool $marketingConsent = false,
        public readonly bool $whatsappOptIn = false,
        public readonly array $consentProof = [],
    ) {}
}
