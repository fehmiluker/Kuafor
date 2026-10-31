<?php

namespace App\Enums;

/** In-salon payment methods. Recorded for reporting only — no gateway. */
enum PaymentMethod: string
{
    use HasValues;

    case Cash = 'cash';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Nakit',
            self::Card => 'Kart',
            self::Other => 'Diğer',
        };
    }
}
