<?php

namespace App\Enums;

/** Role keys. Tenant roles are resolved inside the tenant (Spatie team = tenant_id). */
enum TenantRole: string
{
    use HasValues;

    case Owner = 'tenant_owner';
    case Admin = 'tenant_admin';
    case BranchManager = 'branch_manager';
    case Receptionist = 'receptionist';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'İşletme sahibi',
            self::Admin => 'İşletme yöneticisi',
            self::BranchManager => 'Şube müdürü',
            self::Receptionist => 'Resepsiyon',
            self::Employee => 'Çalışan',
        };
    }

    /** Roles whose access is limited to explicitly assigned branches. */
    public function isBranchScoped(): bool
    {
        return in_array($this, [self::BranchManager, self::Receptionist, self::Employee], true);
    }
}
