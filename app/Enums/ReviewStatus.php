<?php

namespace App\Enums;

enum ReviewStatus: string
{
    use HasValues;

    case Published = 'published';
    case Hidden = 'hidden';
    case UnderReview = 'under_review';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Yayında',
            self::Hidden => 'Gizlendi',
            self::UnderReview => 'İncelemede',
        };
    }
}
