<?php

namespace App\Support;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;

/** Single source of truth for the role -> permission matrix (also documented in docs/security.md). */
final class Permissions
{
    public const TENANT = [
        'branches.manage', 'employees.manage', 'services.manage',
        'appointments.view', 'appointments.create', 'appointments.approve', 'appointments.reschedule',
        'appointments.cancel', 'appointments.complete',
        'customers.view', 'customers.manage', 'reports.view', 'whatsapp.manage',
        'reviews.reply', 'settings.manage', 'subscriptions.manage',
    ];

    public const PLATFORM = [
        'tenants.manage', 'tenants.view', 'plans.manage', 'platform.subscriptions.manage',
        'reviews.moderate', 'audit.view', 'platform.settings', 'pii.access',
    ];

    /** @return array<string, list<string>> */
    public static function tenantMatrix(): array
    {
        $all = self::TENANT;

        return [
            TenantRole::Owner->value => $all,
            TenantRole::Admin->value => array_values(array_diff($all, ['subscriptions.manage'])),
            TenantRole::BranchManager->value => [
                'employees.manage', 'appointments.view', 'appointments.create', 'appointments.approve',
                'appointments.reschedule', 'appointments.cancel', 'appointments.complete',
                'customers.view', 'customers.manage', 'reports.view', 'whatsapp.manage', 'reviews.reply',
            ],
            TenantRole::Receptionist->value => [
                'appointments.view', 'appointments.create', 'appointments.approve', 'appointments.reschedule',
                'appointments.cancel', 'appointments.complete', 'customers.view', 'customers.manage',
            ],
            TenantRole::Employee->value => ['appointments.view', 'appointments.complete'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function platformMatrix(): array
    {
        return [
            PlatformRole::SuperAdmin->value => self::PLATFORM,
            // Support: read-only tenant view + moderation; no PII, plans or billing.
            PlatformRole::Support->value => ['tenants.view', 'reviews.moderate', 'audit.view'],
            PlatformRole::Customer->value => [],
        ];
    }
}
