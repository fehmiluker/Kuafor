<?php

namespace App\Enums;

/** Generic active/inactive status used by branches, employees, users, customers. */
enum RecordStatus: string
{
    use HasValues;

    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Pasif',
            self::Blocked => 'Engelli',
        };
    }
}
