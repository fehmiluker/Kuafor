<?php

namespace App\Enums;

enum AppointmentSource: string
{
    use HasValues;

    case Web = 'web';
    case Api = 'api';
    case Panel = 'panel';
    case WhatsApp = 'whatsapp';
}
