<?php

namespace Tests\Unit;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_istanbul_is_utc_plus_three(): void
    {
        $this->assertSame('2026-11-02 07:00:00', LocalTime::toUtc('2026-11-02', '10:00', 'Europe/Istanbul')->format('Y-m-d H:i:s'));
    }

    public function test_dst_day_has_23_hours_in_berlin(): void
    {
        [$s, $e] = LocalTime::dayBoundsUtc('2026-03-29', 'Europe/Berlin');
        $this->assertSame(23, (int) $s->diffInHours($e));
        [$s, $e] = LocalTime::dayBoundsUtc('2026-10-25', 'Europe/Berlin');
        $this->assertSame(25, (int) $s->diffInHours($e));
    }

    public function test_dates_covered_across_midnight(): void
    {
        $start = CarbonImmutable::parse('2026-11-02 23:30', 'Europe/Istanbul')->utc();
        $end = CarbonImmutable::parse('2026-11-03 00:30', 'Europe/Istanbul')->utc();
        $this->assertSame(['2026-11-02', '2026-11-03'], LocalTime::localDatesCovered($start, $end, 'Europe/Istanbul'));
    }

    public function test_interval_ending_exactly_at_midnight_covers_one_day(): void
    {
        $start = CarbonImmutable::parse('2026-11-02 23:00', 'Europe/Istanbul')->utc();
        $end = CarbonImmutable::parse('2026-11-03 00:00', 'Europe/Istanbul')->utc();
        $this->assertSame(['2026-11-02'], LocalTime::localDatesCovered($start, $end, 'Europe/Istanbul'));
    }
}
