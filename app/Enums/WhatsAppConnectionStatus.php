<?php

namespace App\Enums;

enum WhatsAppConnectionStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Connected = 'connected';
    case Error = 'error';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bağlantı bekliyor',
            self::Connected => 'Bağlı',
            self::Error => 'Hata',
            self::Disconnected => 'Bağlantı kesildi',
        };
    }
}
