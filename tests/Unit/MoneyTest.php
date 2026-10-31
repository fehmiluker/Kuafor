<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_and_turkish_formats_without_floats(): void
    {
        $this->assertSame(123450, Money::parse('1234.50')->minor);
        $this->assertSame(123450, Money::parse('1.234,50')->minor);
        $this->assertSame(123405, Money::parse('1234.05')->minor);
        $this->assertSame(30000, Money::parse(300)->minor);
        $this->assertSame('0.10', Money::parse('0.1')->toDecimal());
    }

    public function test_classic_float_trap_is_exact(): void
    {
        $sum = Money::parse('0.10')->plus(Money::parse('0.20'));
        $this->assertTrue($sum->equals(Money::parse('0.30')));
    }

    public function test_formats_turkish_lira(): void
    {
        $this->assertSame('1.234,50 ₺', Money::parse('1234.50')->format());
    }

    public function test_percent_rounds_half_up(): void
    {
        $this->assertSame(2000, Money::parse('100.00')->percent(20)->minor);
        $this->assertSame(1, Money::ofMinor(5)->percent(10)->minor); // 0.5 -> 1
    }

    public function test_rejects_invalid_and_mixed_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('12.345');
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('1', 'TRY')->plus(Money::parse('1', 'USD'));
    }
}
