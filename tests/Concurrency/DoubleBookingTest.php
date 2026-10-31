<?php

namespace Tests\Concurrency;

use App\DTOs\Appointments\Actor;
use App\Exceptions\BookingConflictException;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Tenant;
use App\Services\Booking\AppointmentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalon;

class DoubleBookingTest extends ConcurrencyTestCase
{
    use BuildsSalon;

    private function bookWorker(int $i, ?int $employeeId, string $time, ?string $key = null): callable
    {
        $tenantId = $this->tenant->id;

        return function () use ($i, $employeeId, $time, $key, $tenantId) {
            try {
                $tenant = Tenant::findOrFail($tenantId);
                $r = app(AppointmentService::class)->create($tenant, $this->bookingData(
                    $this->at($time), $employeeId, [], '+90555100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    $key ? ['idempotencyKey' => $key] : [],
                ));

                return ($r['created'] ? 'ok:' : 'dup:').$r['appointment']->id.':'.$r['appointment']->employee_id;
            } catch (BookingConflictException) {
                return 'conflict';
            }
        };
    }

    public function test_parallel_requests_for_same_employee_slot_yield_exactly_one_booking(): void
    {
        $this->buildSalon('automatic');
        $results = $this->race(array_map(fn ($i) => $this->bookWorker($i, $this->ayse->id, '10:00'), range(1, 8)));

        $ok = array_filter($results, fn ($r) => str_starts_with($r, 'ok:'));
        $this->assertCount(1, $ok, 'results: '.implode(', ', $results));
        $this->assertCount(7, array_filter($results, fn ($r) => $r === 'conflict'), implode(', ', $results));
        $this->assertSame(1, DB::table('appointments')->where('employee_id', $this->ayse->id)->count());
    }

    public function test_overlapping_but_not_identical_slots_also_serialize(): void
    {
        $this->buildSalon('automatic');
        $times = ['10:00', '10:15', '10:15', '10:00', '09:45', '10:30'];
        $results = $this->race(array_map(fn ($i) => $this->bookWorker($i, $this->ayse->id, $times[$i]), array_keys($times)));
        // Whatever wins, no two stored bookings for Ayşe may overlap (including buffers).
        $rows = DB::table('appointments')->where('employee_id', $this->ayse->id)->orderBy('starts_at_utc')->get();
        for ($i = 1; $i < count($rows); $i++) {
            $this->assertGreaterThanOrEqual($rows[$i - 1]->blocked_until_utc, $rows[$i]->starts_at_utc, 'overlap: '.implode(', ', $results));
        }
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    public function test_any_employee_race_fills_each_employee_once(): void
    {
        $this->buildSalon('automatic');
        $results = $this->race(array_map(fn ($i) => $this->bookWorker($i, null, '11:00'), range(1, 6)));
        $ok = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'ok:')));
        $this->assertCount(2, $ok, implode(', ', $results)); // Ayşe and Mehmet
        $employees = array_map(fn ($r) => explode(':', $r)[2], $ok);
        $this->assertEqualsCanonicalizing([(string) $this->ayse->id, (string) $this->mehmet->id], $employees);
    }

    public function test_double_click_with_same_idempotency_key_creates_one_row(): void
    {
        $this->buildSalon('automatic');
        $results = $this->race(array_map(fn ($i) => $this->bookWorker(1, $this->ayse->id, '12:00', 'click-xyz'), range(1, 5)));
        $this->assertSame(1, Appointment::withoutGlobalScopes()->where('idempotency_key', 'click-xyz')->count(), implode(', ', $results));
        $ids = array_unique(array_map(fn ($r) => explode(':', $r)[1] ?? $r, array_filter($results, fn ($r) => $r !== 'conflict')));
        $this->assertCount(1, $ids, implode(', ', $results));
    }

    public function test_proposal_acceptance_races_new_booking_for_same_slot(): void
    {
        $this->buildSalon('manual');
        $pending = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $proposal = app(AppointmentService::class)->propose($pending, $this->at('15:00'), $this->ayse->id, Actor::system('test'))['proposal'];
        $this->builder->settings(['approval_mode' => 'automatic']);
        $proposalId = $proposal->id;

        $workers = [
            function () use ($proposalId) {
                try {
                    $p = AppointmentProposal::withoutGlobalScopes()->findOrFail($proposalId);

                    return 'accept:'.app(AppointmentService::class)->respondToProposal($p, true, Actor::guest())->status->value;
                } catch (\Throwable $e) {
                    return 'accept-failed:'.class_basename($e);
                }
            },
            $this->bookWorker(50, $this->ayse->id, '15:00'),
            $this->bookWorker(51, $this->ayse->id, '15:00'),
        ];
        $results = $this->race($workers);
        $this->assertSame('accept:confirmed', $results[0], implode(', ', $results));
        $this->assertSame(['conflict', 'conflict'], array_slice($results, 1), 'held proposal slot must not be sold');
        $this->assertSame(1, DB::table('appointments')->where('employee_id', $this->ayse->id)->where('starts_at_utc', $this->at('15:00'))->count());
    }
}
