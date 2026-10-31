<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    use HasValues;

    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Statuses in which the tenant may operate (panel + bookings). */
    public static function usable(): array
    {
        return [self::Trialing->value, self::Active->value, self::PastDue->value, self::GracePeriod->value];
    }

    public function isUsable(): bool
    {
        return in_array($this->value, self::usable(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Deneme',
            self::Active => 'Aktif',
            self::PastDue => 'Ödeme gecikmede',
            self::GracePeriod => 'Ek süre',
            self::Suspended => 'Askıda',
            self::Cancelled => 'İptal edildi',
            self::Expired => 'Süresi doldu',
        };
    }
}
