<?php

namespace Tests\Feature\Booking;

use App\Models\Branch;
use App\Services\Booking\AvailabilityService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use BuildsSalon;

    private function slots(array $services, ?int $employee = null, string $date = '2026-11-02', ?Branch $branch = null): array
    {
        return array_map(
            fn ($s) => $s['start']->setTimezone('Europe/Istanbul')->format('H:i'),
            app(AvailabilityService::class)->slotsForDay($branch ?? $this->branch, $services, $employee, $date),
        );
    }

    public function test_slots_respect_opening_hours_notice_and_interval(): void
    {
        $this->buildSalon();
        $slots = $this->slots([$this->cut->id], $this->ayse->id);
        // now = 08:00, notice 30 min, opens 09:00 -> first slot 09:00; 30-min cut must end by 19:00.
        $this->assertSame('09:00', $slots[0]);
        $this->assertSame('09:15', $slots[1]);
        $this->assertSame('18:30', end($slots));
    }

    public function test_min_notice_hides_near_slots(): void
    {
        $this->buildSalon();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:05', 'Europe/Istanbul'));
        $this->assertSame('12:45', $this->slots([$this->cut->id], $this->ayse->id)[0]);
    }

    public function test_confirmed_appointment_and_its_buffer_block_the_employee(): void
    {
        $this->buildSalon();
        $this->book($this->at('10:00'), $this->ayse->id); // 10:00-10:30 + 10 min buffer -> blocked until 10:40
        $slots = $this->slots([$this->cut->id], $this->ayse->id);
        foreach (['09:45', '10:00', '10:15', '10:30'] as $t) {
            $this->assertNotContains($t, $slots, "$t should be blocked");
        }
        $this->assertContains('09:15', $slots); // 09:15-09:45 + buffer 09:55 < 10:00
        $this->assertContains('10:45', $slots);
    }

    public function test_multi_service_duration_is_summed(): void
    {
        $this->buildSalon();
        $slots = $this->slots([$this->cut->id, $this->color->id], $this->ayse->id);
        // 120 minutes total -> last start 17:00 (ends 19:00)
        $this->assertSame('17:00', end($slots));
    }

    public function test_employee_without_skill_is_not_offered(): void
    {
        $this->buildSalon();
        $this->assertSame([], $this->slots([$this->color->id], $this->mehmet->id));
    }

    public function test_any_employee_slot_lists_all_qualified_employees(): void
    {
        $this->buildSalon();
        $result = app(AvailabilityService::class)->slotsForDay($this->branch, [$this->cut->id], null, '2026-11-02');
        $this->assertEqualsCanonicalizing([$this->ayse->id, $this->mehmet->id], $result[0]['employee_ids']);
    }

    public function test_breaks_leaves_and_blocked_times_are_excluded(): void
    {
        $this->buildSalon();
        DB::table('employee_breaks')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_id' => $this->ayse->id,
            'day_of_week' => 1, 'starts_local' => '12:00', 'ends_local' => '13:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_leaves')->insert(['tenant_id' => $this->tenant->id, 'employee_id' => $this->ayse->id, 'starts_at_utc' => $this->at('15:00'),
            'ends_at_utc' => $this->at('16:00'), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blocked_times')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_id' => null,
            'starts_at_utc' => $this->at('17:00'), 'ends_at_utc' => $this->at('19:00'), 'reason' => 'Toplantı', 'created_at' => now(), 'updated_at' => now()]);
        $slots = $this->slots([$this->cut->id], $this->ayse->id);
        $this->assertNotContains('12:00', $slots);
        $this->assertNotContains('11:45', $slots); // would run into the break
        $this->assertContains('13:00', $slots);
        $this->assertNotContains('15:00', $slots);
        $this->assertNotContains('14:30', $slots); // 14:30 + 30 + buffer 10 overlaps leave
        $this->assertNotContains('17:00', $slots);
        $this->assertSame('16:15', end($slots));
    }

    public function test_employee_shift_narrows_branch_hours(): void
    {
        $this->buildSalon();
        DB::table('working_hours')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_id' => $this->mehmet->id,
            'day_of_week' => 1, 'starts_local' => '13:00', 'ends_local' => '22:00', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $slots = $this->slots([$this->cut->id], $this->mehmet->id);
        $this->assertSame('13:00', $slots[0]);
        $this->assertSame('18:30', end($slots)); // capped by branch closing 19:00
        // Mehmet has shifts defined but none on Tuesday -> off.
        $this->assertSame([], $this->slots([$this->cut->id], $this->mehmet->id, '2026-11-03'));
    }

    public function test_horizon_and_past_dates(): void
    {
        $this->buildSalon();
        $this->assertSame([], $this->slots([$this->cut->id], null, '2026-11-01'));
        $this->assertSame([], $this->slots([$this->cut->id], null, '2027-02-01')); // > 60 days
    }

    public function test_dst_branch_generates_correct_utc_slots(): void
    {
        $this->buildSalon();
        $berlin = app(TenantContext::class)->run($this->tenant, fn () => $this->builder->branch('Berlin', ['timezone' => 'Europe/Berlin', 'slug' => 'berlin']));
        DB::table('branch_services')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $berlin->id, 'service_id' => $this->cut->id, 'active' => true]);
        DB::table('employee_branches')->insert(['tenant_id' => $this->tenant->id, 'employee_id' => $this->ayse->id, 'branch_id' => $berlin->id, 'active' => true]);
        $berlin->setRelation('tenant', $this->tenant);
        $this->travelTo(CarbonImmutable::parse('2027-03-27 12:00', 'UTC'));
        // 2027-03-28 (Sunday) is the spring-forward day in Berlin; Sunday hours are 10:00-18:00 local.
        $result = app(AvailabilityService::class)->slotsForDay($berlin, [$this->cut->id], $this->ayse->id, '2027-03-28');
        $this->assertSame('2027-03-28 08:00', $result[0]['start']->format('Y-m-d H:i')); // 10:00 CEST = 08:00 UTC
    }
}
