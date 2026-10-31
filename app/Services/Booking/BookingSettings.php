<?php

namespace App\Services\Booking;

use App\Enums\ApprovalMode;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Settings\SettingsRepository;

/** Effective booking rules: config defaults <- tenant_settings <- branch_settings. */
final class BookingSettings
{
    public const KEYS = [
        'approval_mode', 'slot_interval_minutes', 'min_notice_minutes', 'max_horizon_days',
        'cancellation_cutoff_minutes', 'pending_ttl_minutes', 'proposal_ttl_minutes',
        'reminder_hours_before', 'require_phone_verification', 'max_pending_per_phone',
    ];

    public function __construct(
        public readonly ApprovalMode $approvalMode,
        public readonly int $slotIntervalMinutes,
        public readonly int $minNoticeMinutes,
        public readonly int $maxHorizonDays,
        public readonly int $cancellationCutoffMinutes,
        public readonly int $pendingTtlMinutes,
        public readonly int $proposalTtlMinutes,
        public readonly int $reminderHoursBefore,
        public readonly bool $requirePhoneVerification,
        public readonly int $maxPendingPerPhone,
    ) {}

    public static function for(Tenant $tenant, ?Branch $branch = null): self
    {
        $repo = app(SettingsRepository::class);
        $values = array_merge(
            config('booking.defaults'),
            array_intersect_key($repo->tenant($tenant), array_flip(self::KEYS)),
            $branch ? array_intersect_key($repo->branch($branch), array_flip(self::KEYS)) : [],
        );
        $minTtl = (int) config('booking.min_ttl_minutes');

        return new self(
            approvalMode: ApprovalMode::tryFrom((string) $values['approval_mode']) ?? ApprovalMode::Manual,
            slotIntervalMinutes: max(5, (int) $values['slot_interval_minutes']),
            minNoticeMinutes: max(0, (int) $values['min_notice_minutes']),
            maxHorizonDays: max(1, (int) $values['max_horizon_days']),
            cancellationCutoffMinutes: max(0, (int) $values['cancellation_cutoff_minutes']),
            pendingTtlMinutes: max($minTtl, (int) $values['pending_ttl_minutes']),
            proposalTtlMinutes: max($minTtl, (int) $values['proposal_ttl_minutes']),
            reminderHoursBefore: max(1, (int) $values['reminder_hours_before']),
            requirePhoneVerification: (bool) $values['require_phone_verification'],
            maxPendingPerPhone: max(1, (int) $values['max_pending_per_phone']),
        );
    }

    public function toArray(): array
    {
        return [
            'approval_mode' => $this->approvalMode->value,
            'slot_interval_minutes' => $this->slotIntervalMinutes,
            'min_notice_minutes' => $this->minNoticeMinutes,
            'max_horizon_days' => $this->maxHorizonDays,
            'cancellation_cutoff_minutes' => $this->cancellationCutoffMinutes,
            'pending_ttl_minutes' => $this->pendingTtlMinutes,
            'proposal_ttl_minutes' => $this->proposalTtlMinutes,
            'reminder_hours_before' => $this->reminderHoursBefore,
            'require_phone_verification' => $this->requirePhoneVerification,
            'max_pending_per_phone' => $this->maxPendingPerPhone,
        ];
    }
}
