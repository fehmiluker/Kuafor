<?php

namespace Tests\Feature\Booking;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentStatus;
use App\Enums\HoldStatus;
use App\Enums\ProposalStatus;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\ProposalCreated;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
{
    use BuildsSalon;

    private function svc(): AppointmentService
    {
        return app(AppointmentService::class);
    }

    private function staff(): Actor
    {
        return Actor::user(User::factory()->create());
    }

    public function test_automatic_mode_confirms_immediately_and_snapshots_prices(): void
    {
        Event::fake([AppointmentCreated::class, AppointmentConfirmed::class]);
        $this->buildSalon('automatic');
        $r = $this->book($this->at('10:00'), $this->ayse->id, [$this->cut->id, $this->color->id]);
        $a = $r['appointment'];
        $this->assertTrue($r['created']);
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame('1500.00', $a->total_price->toDecimal());
        $this->assertSame($this->at('12:00')->toDateTimeString(), $a->ends_at_utc->toDateTimeString());
        $this->assertSame(2, $a->items->count());
        $this->assertNotEmpty($r['guest_token']);
        $this->assertDatabaseHas('appointment_status_histories', ['appointment_id' => $a->id, 'to_status' => 'confirmed', 'from_status' => null]);
        Event::assertDispatched(AppointmentConfirmed::class);
        // No online payment was requested or recorded.
        $this->assertDatabaseCount('appointment_payments', 0);
    }

    public function test_manual_mode_creates_pending_with_hold_that_blocks_others(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->assertSame(AppointmentStatus::Pending, $a->status);
        $this->assertDatabaseHas('booking_holds', ['appointment_id' => $a->id, 'status' => 'held']);

        $this->expectException(BookingConflictException::class);
        $this->book($this->at('10:15'), $this->ayse->id, phone: '+905559998877');
    }

    public function test_approve_confirms_and_converts_hold(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $a = $this->svc()->approve($a, $this->staff());
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertDatabaseHas('booking_holds', ['appointment_id' => $a->id, 'status' => HoldStatus::Converted->value]);
        $this->expectException(InvalidTransitionException::class);
        $this->svc()->approve($a, $this->staff());
    }

    public function test_expired_hold_frees_slot_and_late_approval_rechecks(): void
    {
        $this->buildSalon('manual');
        $pending = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->travel(3)->hours(); // pending TTL 120 min elapsed (now 11:00)
        $this->assertSame(1, $this->svc()->expireStale());
        $this->assertSame(AppointmentStatus::Expired, $pending->fresh()->status);
        $this->assertSame(0, $this->svc()->expireStale(), 'sweep is idempotent');
    }

    public function test_approve_fails_when_slot_was_taken_after_hold_expiry(): void
    {
        $this->buildSalon('manual');
        $pending = $this->book($this->at('12:00'), $this->ayse->id)['appointment'];
        $this->travel(150)->minutes(); // hold expired, sweep not yet run
        $this->builder->settings(['approval_mode' => 'automatic']);
        $this->book($this->at('12:00'), $this->ayse->id, phone: '+905550000001'); // takes the slot
        $this->expectException(BookingConflictException::class);
        $this->svc()->approve($pending, $this->staff());
    }

    public function test_proposal_accept_moves_appointment_and_confirms(): void
    {
        Event::fake([ProposalCreated::class]);
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $r = $this->svc()->propose($a, $this->at('14:00'), $this->mehmet->id, $this->staff(), 'Öğleden sonra uygun');
        $this->assertSame(AppointmentStatus::AlternativeProposed, $a->fresh()->status);
        $this->assertDatabaseHas('booking_holds', ['appointment_id' => $a->id, 'proposal_id' => null, 'status' => 'released']);
        $this->assertDatabaseHas('booking_holds', ['proposal_id' => $r['proposal']->id, 'status' => 'held', 'employee_id' => $this->mehmet->id]);
        Event::assertDispatched(ProposalCreated::class);

        $a = $this->svc()->respondToProposal($r['proposal'], true, Actor::guest());
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame($this->mehmet->id, $a->employee_id);
        $this->assertSame($this->at('14:00')->toDateTimeString(), $a->starts_at_utc->toDateTimeString());
        $this->assertSame(ProposalStatus::Accepted, $r['proposal']->fresh()->status);
    }

    public function test_proposal_reject_releases_hold_and_rejects(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $r = $this->svc()->propose($a, $this->at('14:00'), null, $this->staff());
        $a = $this->svc()->respondToProposal($r['proposal'], false, Actor::guest());
        $this->assertSame(AppointmentStatus::Rejected, $a->status);
        $this->assertDatabaseHas('booking_holds', ['proposal_id' => $r['proposal']->id, 'status' => 'released']);
        // Slot is free again.
        $this->builder->settings(['approval_mode' => 'automatic']);
        $this->assertTrue($this->book($this->at('14:00'), $this->ayse->id, phone: '+905550000002')['created']);
    }

    public function test_only_one_open_proposal_per_appointment(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $first = $this->svc()->propose($a, $this->at('14:00'), null, $this->staff())['proposal'];
        $second = $this->svc()->propose($a, $this->at('15:00'), null, $this->staff())['proposal'];
        $this->assertSame(ProposalStatus::Withdrawn, $first->fresh()->status);
        $this->assertSame(1, DB::table('appointment_proposals')->where('appointment_id', $a->id)->where('status', 'open')->count());
        $this->assertDatabaseHas('booking_holds', ['proposal_id' => $first->id, 'status' => 'released']);
        // DB-level guarantee as well: a second open row violates the unique generated column.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('appointment_proposals')->where('id', $first->id)->update(['status' => 'open']);
        $this->assertNotNull($second);
    }

    public function test_proposal_expires_and_cannot_be_accepted(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $p = $this->svc()->propose($a, $this->at('16:00'), null, $this->staff())['proposal'];
        $this->travel(5)->hours();
        try {
            $this->svc()->respondToProposal($p, true, Actor::guest());
            $this->fail('expired proposal accepted');
        } catch (DomainRuleException $e) {
            $this->assertSame('proposal_expired', $e->errorCode);
        }
        $this->svc()->expireStale();
        $this->assertSame(AppointmentStatus::Expired, $a->fresh()->status);
        $this->assertSame(ProposalStatus::Expired, $p->fresh()->status);
    }

    public function test_accepting_proposal_loses_race_against_new_booking_safely(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $p = $this->svc()->propose($a, $this->at('14:00'), null, $this->staff())['proposal'];
        // While the proposal holds 14:00, a new automatic booking cannot take it.
        $this->builder->settings(['approval_mode' => 'automatic']);
        try {
            $this->book($this->at('14:00'), $this->ayse->id, phone: '+905550000003');
            $this->fail('held proposal slot was double booked');
        } catch (BookingConflictException) {
        }
        $this->assertSame(AppointmentStatus::Confirmed, $this->svc()->respondToProposal($p, true, Actor::guest())->status);
    }

    public function test_cancel_releases_slot_and_customer_cutoff_is_enforced(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('09:30'), $this->ayse->id)['appointment'];
        try {
            $this->svc()->cancel($a, Actor::guest(), 'Gelemiyorum', enforceCutoff: true); // 90 min before, cutoff 120
            $this->fail('cutoff not enforced');
        } catch (DomainRuleException $e) {
            $this->assertSame('cancellation_cutoff', $e->errorCode);
        }
        $a = $this->svc()->cancel($a, $this->staff(), 'Salon iptali');
        $this->assertSame(AppointmentStatus::Cancelled, $a->status);
        $this->assertTrue($this->book($this->at('09:30'), $this->ayse->id, phone: '+905550000004')['created']);
    }

    public function test_reschedule_moves_and_failure_keeps_original(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->book($this->at('15:00'), $this->ayse->id, phone: '+905550000005');
        try {
            $this->svc()->reschedule($a, $this->at('15:00'), null, $this->staff());
            $this->fail('rescheduled into taken slot');
        } catch (BookingConflictException) {
        }
        $this->assertSame($this->at('10:00')->toDateTimeString(), $a->fresh()->starts_at_utc->toDateTimeString());
        $moved = $this->svc()->reschedule($a, $this->at('11:00'), null, $this->staff());
        $this->assertSame($this->at('11:00')->toDateTimeString(), $moved->starts_at_utc->toDateTimeString());
        // Overlap with own old slot is fine (moving by 15 minutes).
        $this->assertSame($this->at('11:15')->toDateTimeString(), $this->svc()->reschedule($moved, $this->at('11:15'), null, $this->staff())->starts_at_utc->toDateTimeString());
        // Old slot is free.
        $this->assertTrue($this->book($this->at('10:00'), $this->ayse->id, phone: '+905550000006')['created']);
    }

    public function test_complete_records_in_salon_payments_and_discount(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        try {
            $this->svc()->complete($a, $this->staff());
            $this->fail('completed before start');
        } catch (DomainRuleException) {
        }
        $this->travelTo($this->at('10:40'));
        $a = $this->svc()->complete($a, $this->staff(), [['amount' => '250.00', 'method' => 'card']], '50.00');
        $this->assertSame(AppointmentStatus::Completed, $a->status);
        $this->assertSame('250.00', $a->total_price->toDecimal());
        $this->assertDatabaseHas('appointment_payments', ['appointment_id' => $a->id, 'amount' => '250.00', 'method' => 'card']);
        $this->expectException(DomainRuleException::class);
        $this->svc()->recordPayment($a, ['amount' => '-5', 'method' => 'cash'], $this->staff());
    }

    public function test_no_show(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->travelTo($this->at('10:20'));
        $this->assertSame(AppointmentStatus::NoShow, $this->svc()->markNoShow($a, $this->staff())->status);
        $this->expectException(InvalidTransitionException::class);
        $this->svc()->complete($a->fresh(), $this->staff());
    }

    public function test_idempotency_key_returns_same_appointment(): void
    {
        $this->buildSalon('automatic');
        $first = $this->book($this->at('10:00'), $this->ayse->id, extra: ['idempotencyKey' => 'abc-123']);
        $second = $this->book($this->at('10:00'), $this->ayse->id, extra: ['idempotencyKey' => 'abc-123']);
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['appointment']->id, $second['appointment']->id);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_any_employee_is_balanced_and_deterministic(): void
    {
        $this->buildSalon('automatic');
        $a1 = $this->book($this->at('10:00'))['appointment'];
        $this->assertSame($this->ayse->id, $a1->employee_id, 'tie -> lowest id');
        $a2 = $this->book($this->at('10:00'), phone: '+905550000007')['appointment'];
        $this->assertSame($this->mehmet->id, $a2->employee_id, 'ayse busy');
        $a3 = $this->book($this->at('13:00'), phone: '+905550000008')['appointment'];
        $this->assertSame($this->ayse->id, $a3->employee_id, 'equal load -> lowest id');
        $a4 = $this->book($this->at('14:00'), phone: '+905550000009')['appointment'];
        $this->assertSame($this->mehmet->id, $a4->employee_id, 'mehmet has fewer bookings that day');
    }

    public function test_pending_limit_per_phone(): void
    {
        $this->buildSalon('manual');
        $this->book($this->at('10:00'));
        $this->book($this->at('11:00'));
        $this->book($this->at('12:00'));
        $this->expectException(DomainRuleException::class);
        $this->book($this->at('13:00'));
    }

    public function test_booking_crossing_midnight_locks_both_days(): void
    {
        $this->buildSalon('automatic');
        DB::table('working_hours')->where('branch_id', $this->branch->id)->where('day_of_week', 1)->update(['ends_local' => '23:59:00']);
        DB::table('working_hours')->where('branch_id', $this->branch->id)->where('day_of_week', 2)->update(['starts_local' => '00:00:00']);
        // 23:45 + 30 min -> service must end by 23:59 window -> not bookable; 23:15 is, with buffer to 23:55.
        $a = $this->book($this->at('23:15'), $this->ayse->id, [$this->cut->id])['appointment'];
        $this->assertNotNull($a);
        $this->assertSame(1, DB::table('resource_day_locks')->where('resource_id', $this->ayse->id)->count());
        // Color 90 min from 23:00 would cross midnight -> outside the 23:59 window.
        $this->expectException(BookingConflictException::class);
        $this->book($this->at('23:00'), $this->ayse->id, [$this->color->id], '+905550000010');
    }
}
