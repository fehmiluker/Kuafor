<?php

namespace App\Enums;

enum SalonApplicationStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
        };
    }
}
