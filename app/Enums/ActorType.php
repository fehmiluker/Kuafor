<?php

namespace App\Enums;

enum ActorType: string
{
    use HasValues;

    case User = 'user';
    case Guest = 'guest';
    case System = 'system';
    case WhatsApp = 'whatsapp';
}
