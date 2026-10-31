<?php

namespace App\Enums;

enum HoldStatus: string
{
    use HasValues;

    case Held = 'held';
    case Converted = 'converted';
    case Released = 'released';
    case Expired = 'expired';
}
