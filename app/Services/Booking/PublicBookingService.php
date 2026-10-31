<?php

namespace App\Services\Booking;

use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Exceptions\DomainRuleException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Catalog\PublicCatalog;

/** Shared by the public API and the Livewire booking wizard. */
final class PublicBookingService
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly PublicCatalog $catalog,
        private readonly PhoneVerificationService $otp,
    ) {}

    /** @return array{appointment: Appointment, guest_token: ?string, created: bool} */
    public function book(Branch $branch, array $input, ?User $user, AppointmentSource $source, ?string $idempotencyKey = null, ?string $ip = null): array
    {
        $tenant = $branch->tenant;
        $settings = BookingSettings::for($tenant, $branch);
        $phoneVerified = $user && $user->phone_verified_at && $user->phone_e164 === $input['phone'];
        if ($settings->requirePhoneVerification && ! $phoneVerified) {
            if (empty($input['verification_code']) || ! $this->otp->verify($input['phone'], 'booking', (string) $input['verification_code'])) {
                throw new DomainRuleException('Telefon doğrulama kodu gerekli veya hatalı.', 'phone_verification_required');
            }
        }

        $result = $this->appointments->create($tenant, new CreateAppointmentData(
            branchId: $branch->id,
            serviceIds: array_map('intval', $input['service_ids']),
            employeeId: ! empty($input['employee_id']) ? (int) $input['employee_id'] : null,
            startsAtUtc: $this->catalog->toUtc($branch, $input['date'], $input['time']),
            customerName: trim($input['full_name']),
            customerPhoneE164: $input['phone'],
            customerEmail: $input['email'] ?? null,
            customerNote: isset($input['note']) ? trim(strip_tags($input['note'])) : null,
            userId: $user?->id,
            source: $source,
            idempotencyKey: $idempotencyKey,
            marketingConsent: (bool) ($input['marketing_opt_in'] ?? false),
            whatsappOptIn: (bool) ($input['whatsapp_opt_in'] ?? false),
            consentProof: ['ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null, 'text_version' => config('app.consent_text_version', '2026-01')],
        ));

        return $result;
    }
}
