<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\HoldStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes bookable slots and validates a single slot.
 *
 * Inputs considered: branch opening hours, employee shifts (fallback: branch hours),
 * effective date ranges, breaks, approved leaves, blocked times / holidays,
 * confirmed appointments (incl. buffers), unexpired holds (pending requests and
 * open proposals), slot interval, minimum notice, booking horizon and the
 * branch timezone (DST-safe: windows are converted per date via tzdata).
 *
 * {@see self::checkSlot()} is the authoritative check and is executed again
 * inside the booking lock with fresh data.
 */
final class AvailabilityService
{
    public function __construct(private readonly ServiceResolver $resolver) {}

    /**
     * @param  list<int>  $serviceIds
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, employee_ids: list<int>}>
     */
    public function slotsForDay(Branch $branch, array $serviceIds, ?int $employeeId, string $localDate, bool $enforceNotice = true, ?Exclusions $ex = null, bool $homeVisit = false): array
    {
        $ex ??= Exclusions::none();
        $settings = BookingSettings::for($branch->tenant, $branch);
        $services = $this->resolver->servicesForBranch($branch, $serviceIds);
        $employees = $this->resolver->qualifiedEmployees($branch, $services->keys()->all(), $employeeId, $homeVisit);

        if (! $this->dateWithinHorizon($branch, $localDate, $settings, $enforceNotice)) {
            return [];
        }

        $bySlot = [];
        foreach ($employees as $employee) {
            $quote = $this->resolver->quote($branch, $employee, $services);
            if ($homeVisit) {
                $quote = $quote->withTravel((int) $branch->home_travel_minutes);
            }
            $day = $this->loadDay($branch, $employee, $localDate, $ex);
            foreach ($day['windows'] as $window) {
                $t = $window->start;
                while ($t->addMinutes($quote->durationMinutes())->lte($window->end)) {
                    if ($this->evaluate($day, $quote, $t, $settings, $enforceNotice) === null) {
                        $key = $t->getTimestamp();
                        $bySlot[$key] ??= ['start' => $t, 'end' => $t->addMinutes($quote->durationMinutes()), 'employee_ids' => []];
                        $bySlot[$key]['employee_ids'][] = $employee->id;
                    }
                    $t = $t->addMinutes($settings->slotIntervalMinutes);
                }
            }
        }
        ksort($bySlot);

        return array_values($bySlot);
    }

    /**
     * Returns null when bookable, otherwise a machine-readable reason.
     */
    public function checkSlot(Branch $branch, Employee $employee, Quote $quote, CarbonImmutable $startUtc, ?Exclusions $ex = null, bool $enforceNotice = true): ?string
    {
        $ex ??= Exclusions::none();
        $settings = BookingSettings::for($branch->tenant, $branch);
        $startUtc = $startUtc->utc();
        $localDate = LocalTime::toLocal($startUtc, $branch->timezone)->toDateString();
        if (! $this->dateWithinHorizon($branch, $localDate, $settings, $enforceNotice)) {
            return 'outside_horizon';
        }
        $day = $this->loadDay($branch, $employee, $localDate, $ex);

        return $this->evaluate($day, $quote, $startUtc, $settings, $enforceNotice);
    }

    private function evaluate(array $day, Quote $quote, CarbonImmutable $start, BookingSettings $settings, bool $enforceNotice): ?string
    {
        $serviceEnd = $start->addMinutes($quote->durationMinutes());
        $blockedEnd = $serviceEnd->addMinutes($quote->blockedAfterMinutes());
        $blockedStart = $start->subMinutes($quote->travelMinutes); // home visit: travel there

        if ($enforceNotice && $start->lt(now()->utc()->addMinutes($settings->minNoticeMinutes))) {
            return 'too_soon';
        }
        if (! $enforceNotice && $start->lt(now()->utc()->subMinutes(5))) {
            return 'in_past';
        }
        $inWindow = false;
        foreach ($day['windows'] as $w) {
            if ($w->contains($start, $serviceEnd)) {
                $inWindow = true;
                break;
            }
        }
        if (! $inWindow) {
            return 'outside_working_hours';
        }
        foreach ($day['breaks'] as $b) {
            if ($b->overlaps($start, $serviceEnd)) {
                return 'break';
            }
        }
        foreach ($day['blockers'] as $b) {
            if ($b->overlaps($blockedStart, $blockedEnd)) {
                return $b->kind;
            }
        }

        return null;
    }

    private function dateWithinHorizon(Branch $branch, string $localDate, BookingSettings $settings, bool $enforce): bool
    {
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $date = CarbonImmutable::parse($localDate, $branch->timezone)->startOfDay();
        if ($date->lt($today)) {
            return false;
        }

        return ! $enforce || $date->lte($today->addDays($settings->maxHorizonDays));
    }

    /**
     * Loads everything relevant to one employee on one local date.
     *
     * @return array{windows: list<Interval>, breaks: list<Interval>, blockers: list<Interval>}
     */
    public function loadDay(Branch $branch, Employee $employee, string $localDate, Exclusions $ex): array
    {
        $tz = $branch->timezone;
        $dow = CarbonImmutable::parse($localDate, $tz)->dayOfWeek; // 0 = Sunday
        [$dayStart, $dayEnd] = LocalTime::dayBoundsUtc($localDate, $tz);
        // Look a bit beyond the day so buffers/appointments crossing midnight are seen.
        $from = $dayStart->subHours(12);
        $to = $dayEnd->addHours(12);

        $hours = DB::table('working_hours')
            ->where('tenant_id', $branch->tenant_id)->where('branch_id', $branch->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->get();
        $effective = fn ($r) => (int) $r->day_of_week === $dow
            && ($r->effective_from === null || $r->effective_from <= $localDate)
            && ($r->effective_to === null || $r->effective_to >= $localDate);

        $branchWindows = $this->toIntervals($hours->whereNull('employee_id')->filter($effective), $localDate, $tz, 'open');
        $employeeHasShifts = $hours->whereNotNull('employee_id')->isNotEmpty();
        $employeeWindows = $employeeHasShifts
            ? $this->toIntervals($hours->whereNotNull('employee_id')->filter($effective), $localDate, $tz, 'shift')
            : $branchWindows;
        $windows = $this->intersect($branchWindows, $employeeWindows);

        $breaks = $this->toIntervals(
            DB::table('employee_breaks')->where('employee_id', $employee->id)->where('branch_id', $branch->id)->where('day_of_week', $dow)->get(),
            $localDate, $tz, 'break');

        $blockers = [];
        $leaves = DB::table('employee_leaves')->where('employee_id', $employee->id)->where('status', 'approved')
            ->where('starts_at_utc', '<', $to)->where('ends_at_utc', '>', $from)->get();
        foreach ($leaves as $l) {
            $blockers[] = new Interval(CarbonImmutable::parse($l->starts_at_utc, 'UTC'), CarbonImmutable::parse($l->ends_at_utc, 'UTC'), 'employee_leave');
        }
        $blocked = DB::table('blocked_times')->where('branch_id', $branch->id)
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->where('starts_at_utc', '<', $to)->where('ends_at_utc', '>', $from)->get();
        foreach ($blocked as $b) {
            $blockers[] = new Interval(CarbonImmutable::parse($b->starts_at_utc, 'UTC'), CarbonImmutable::parse($b->ends_at_utc, 'UTC'), 'blocked_time');
        }
        // Employee may work at several branches: appointments/holds are checked across all of them.
        $appointments = DB::table('appointments')->where('employee_id', $employee->id)
            ->whereIn('status', AppointmentStatus::blocking())
            ->where('starts_at_utc', '<', $to)->where('blocked_until_utc', '>', $from)
            ->when($ex->appointmentId, fn ($q) => $q->where('id', '!=', $ex->appointmentId))
            ->get(['starts_at_utc', 'blocked_until_utc', 'travel_minutes']);
        foreach ($appointments as $a) {
            // Home visits also block the travel time before the start (travel back is inside blocked_until).
            $blockers[] = new Interval(CarbonImmutable::parse($a->starts_at_utc, 'UTC')->subMinutes((int) $a->travel_minutes), CarbonImmutable::parse($a->blocked_until_utc, 'UTC'), 'booked');
        }
        $holds = DB::table('booking_holds')->where('employee_id', $employee->id)
            ->where('status', HoldStatus::Held->value)->where('expires_at_utc', '>', now()->utc())
            ->where('starts_at_utc', '<', $to)->where('ends_at_utc', '>', $from)
            ->when($ex->holdIds, fn ($q) => $q->whereNotIn('id', $ex->holdIds))
            ->when($ex->appointmentId, fn ($q) => $q->where(fn ($w) => $w->whereNull('appointment_id')->orWhere('appointment_id', '!=', $ex->appointmentId)))
            ->get(['starts_at_utc', 'ends_at_utc']);
        foreach ($holds as $h) {
            $blockers[] = new Interval(CarbonImmutable::parse($h->starts_at_utc, 'UTC'), CarbonImmutable::parse($h->ends_at_utc, 'UTC'), 'held');
        }

        return ['windows' => $windows, 'breaks' => $breaks, 'blockers' => $blockers];
    }

    /** @return list<Interval> */
    private function toIntervals(Collection $rows, string $localDate, string $tz, string $kind): array
    {
        $out = [];
        foreach ($rows as $r) {
            $start = LocalTime::toUtc($localDate, $r->starts_local, $tz);
            $end = LocalTime::toUtc($localDate, $r->ends_local, $tz);
            if ($end->gt($start)) {
                $out[] = new Interval($start, $end, $kind);
            }
        }
        usort($out, fn ($a, $b) => $a->start <=> $b->start);

        return $out;
    }

    /**
     * @param  list<Interval>  $a
     * @param  list<Interval>  $b
     * @return list<Interval>
     */
    private function intersect(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s = $x->start->max($y->start);
                $e = $x->end->min($y->end);
                if ($e->gt($s)) {
                    $out[] = new Interval($s, $e, 'window');
                }
            }
        }
        usort($out, fn ($p, $q) => $p->start <=> $q->start);

        return $out;
    }
}
