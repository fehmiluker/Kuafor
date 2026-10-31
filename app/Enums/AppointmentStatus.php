<?php

namespace App\Enums;

/**
 * Appointment state machine. Allowed transitions are defined in one place
 * ({@see self::transitions()}) and enforced by AppointmentService.
 */
enum AppointmentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case AlternativeProposed = 'alternative_proposed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Pending->value => [self::Confirmed, self::AlternativeProposed, self::Rejected, self::Cancelled, self::Expired],
            self::AlternativeProposed->value => [self::Confirmed, self::AlternativeProposed, self::Pending, self::Rejected, self::Cancelled, self::Expired],
            self::Confirmed->value => [self::Completed, self::NoShow, self::Cancelled, self::Confirmed],
            self::Rejected->value => [],
            self::Cancelled->value => [],
            self::Completed->value => [],
            self::NoShow->value => [],
            self::Expired->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value], true);
    }

    public function isTerminal(): bool
    {
        return self::transitions()[$this->value] === [];
    }

    /** Statuses whose appointment row itself blocks the employee's time. */
    public static function blocking(): array
    {
        return [self::Confirmed->value];
    }

    /** Statuses considered "open" (still awaiting a decision or upcoming). */
    public static function active(): array
    {
        return [self::Pending->value, self::AlternativeProposed->value, self::Confirmed->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Onay bekliyor',
            self::Confirmed => 'Onaylandı',
            self::AlternativeProposed => 'Alternatif saat önerildi',
            self::Rejected => 'Reddedildi',
            self::Cancelled => 'İptal edildi',
            self::Completed => 'Tamamlandı',
            self::NoShow => 'Gelmedi',
            self::Expired => 'Süresi doldu',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => '#d97706',
            self::Confirmed => '#2563eb',
            self::AlternativeProposed => '#7c3aed',
            self::Rejected, self::Cancelled => '#6b7280',
            self::Completed => '#059669',
            self::NoShow => '#dc2626',
            self::Expired => '#9ca3af',
        };
    }
}
