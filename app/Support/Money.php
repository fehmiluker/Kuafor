<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value in integer minor units (kuruş). Never uses floats:
 * DECIMAL(12,2) strings from MySQL are parsed digit by digit.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(public readonly int $minor, public readonly string $currency = 'TRY') {}

    public static function ofMinor(int $minor, string $currency = 'TRY'): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'TRY'): self
    {
        return new self(0, strtoupper($currency));
    }

    /** Parse "1234.5", "1234,50", "1.234,50" (TR), 1234 (int). Floats are rejected. */
    public static function parse(string|int $value, string $currency = 'TRY'): self
    {
        if (is_int($value)) {
            return new self($value * 100, strtoupper($currency));
        }
        $v = trim(str_replace(' ', '', $value));
        if ($v === '') {
            throw new InvalidArgumentException('Boş tutar.');
        }
        // Turkish format: dots as thousands, comma as decimal separator.
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $v, $m)) {
            throw new InvalidArgumentException("Geçersiz tutar: {$value}");
        }
        $minor = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$minor : $minor, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function times(int $qty): self
    {
        return new self($this->minor * $qty, $this->currency);
    }

    /** Percentage with half-up rounding, computed in integers. */
    public function percent(int|string $percent): self
    {
        $basisPoints = (int) round(((float) $percent) * 100); // percent has ≤2 decimals -> exact
        $num = $this->minor * $basisPoints;
        $q = intdiv($num, 10000);
        $r = $num % 10000;
        if (abs($r) * 2 >= 10000) {
            $q += $num >= 0 ? 1 : -1;
        }

        return new self($q, $this->currency);
    }

    public function max(self $other): self
    {
        return $this->minor >= $other->minor ? $this : $other;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /** DECIMAL(12,2) string for storage, e.g. "1234.50". */
    public function toDecimal(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Turkish display, e.g. "1.234,50 ₺". */
    public function format(): string
    {
        $abs = abs($this->minor);
        $int = number_format(intdiv($abs, 100), 0, ',', '.');
        $dec = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $this->currency === 'TRY' ? '₺' : $this->currency;

        return ($this->minor < 0 ? '-' : '')."{$int},{$dec} {$symbol}";
    }

    /** Compact display for cards: "₺650", "₺1.800", "₺99,90" (kuruş only when non-zero). */
    public function short(): string
    {
        $abs = abs($this->minor);
        $int = number_format(intdiv($abs, 100), 0, ',', '.');
        $cents = $abs % 100 ? ','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT) : '';
        $symbol = $this->currency === 'TRY' ? '₺' : $this->currency.' ';

        return ($this->minor < 0 ? '-' : '').$symbol.$int.$cents;
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimal(), 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Para birimleri uyuşmuyor.');
        }
    }
}
