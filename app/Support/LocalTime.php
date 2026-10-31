<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timezone helpers. All persisted datetimes are UTC; branch-local times are
 * derived with the branch timezone so DST transitions are handled by tzdata.
 */
final class LocalTime
{
    /** Combine a local date (Y-m-d) and local wall time (H:i[:s]) in $tz into a UTC instant. */
    public static function toUtc(string $date, string $time, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", $tz)->utc();
    }

    public static function toLocal(CarbonInterface|string $utc, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone($tz);
    }

    /** UTC [start, end) covering the whole local day. Length is 23/25h on DST days. */
    public static function dayBoundsUtc(string $date, string $tz): array
    {
        $start = CarbonImmutable::parse($date.' 00:00:00', $tz);

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * Every local date (Y-m-d) touched by a UTC interval, in ascending order.
     * Used to acquire all resource-day locks for bookings that cross midnight.
     *
     * @return list<string>
     */
    public static function localDatesCovered(CarbonInterface $startUtc, CarbonInterface $endUtc, string $tz): array
    {
        $cursor = CarbonImmutable::instance($startUtc)->setTimezone($tz)->startOfDay();
        $last = CarbonImmutable::instance($endUtc)->subSecond()->setTimezone($tz)->startOfDay();
        $dates = [];
        while ($cursor->lte($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    public static function format(CarbonInterface|string|null $utc, string $tz, string $format = 'd.m.Y H:i'): string
    {
        if ($utc === null) {
            return '';
        }

        return self::toLocal($utc, $tz)->locale('tr')->translatedFormat($format);
    }
}
