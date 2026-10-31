<?php

// Defaults for per-tenant booking rules. Tenants override them in tenant_settings,
// branches may override again in branch_settings. See App\Services\Booking\BookingSettings.
return [
    'defaults' => [
        'approval_mode' => 'manual',          // manual | automatic
        'slot_interval_minutes' => 15,
        'min_notice_minutes' => 60,          // earliest bookable time from now
        'max_horizon_days' => 60,            // furthest bookable day
        'cancellation_cutoff_minutes' => 120, // customer may cancel until N minutes before start
        'pending_ttl_minutes' => 120,        // manual-mode hold lifetime awaiting salon approval
        'proposal_ttl_minutes' => 240,       // alternative-time offer lifetime
        'reminder_hours_before' => 24,
        'require_phone_verification' => false,
        'max_pending_per_phone' => 3,        // anti-abuse: open requests per phone per tenant
    ],

    // Lower bound for TTL values regardless of tenant settings.
    'min_ttl_minutes' => 5,

    'guest_token_ttl_days' => 30,

    // Deadlock / lock-wait retries for the atomic booking transaction.
    'transaction_attempts' => 3,

    'max_services_per_appointment' => 5,
];
