<?php

namespace App\Enums;

enum TenantStatus: string
{
    use HasValues;

    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Askıya alındı',
            self::Closed => 'Kapandı',
        };
    }
}
