<?php

namespace App\Enums;

enum ApprovalMode: string
{
    use HasValues;

    case Manual = 'manual';
    case Automatic = 'automatic';

    public function label(): string
    {
        return $this === self::Manual ? 'Manuel onay' : 'Otomatik onay';
    }
}
