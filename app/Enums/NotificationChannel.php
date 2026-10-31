<?php

namespace App\Enums;

enum NotificationChannel: string
{
    use HasValues;

    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Sms = 'sms';
    case Panel = 'panel';
}
