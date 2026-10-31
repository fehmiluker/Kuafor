<?php

namespace App\Enums;

enum ProposalStatus: string
{
    use HasValues;

    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Yanıt bekliyor',
            self::Accepted => 'Kabul edildi',
            self::Rejected => 'Reddedildi',
            self::Expired => 'Süresi doldu',
            self::Withdrawn => 'Geri çekildi',
        };
    }
}
