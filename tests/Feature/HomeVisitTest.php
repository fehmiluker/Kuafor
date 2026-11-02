<?php

namespace Tests\Feature;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\HomeVisit;
use App\Enums\AppointmentStatus;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Jobs\PurgeExpiredData;
use App\Livewire\Public\BookingWizard;
use App\Models\Appointment;
use App\Models\AppointmentAddress;
use App\Models\City;
use App\Models\District;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\AvailabilityService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class HomeVisitTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private City $city;

    private District $served;

    private District $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->city = City::create(['name' => 'Bursa', 'slug' => 'bursa', 'plate_code' => 16]);
        $this->served = District::create(['city_id' => $this->city->id, 'name' => 'Nilüfer', 'slug' => 'nilufer']);
        $this->other = District::create(['city_id' => $this->city->id, 'name' => 'Gemlik', 'slug' => 'gemlik']);
        $this->enableHome();
    }

    private function enableHome(bool $approval = false, ?string $min = null): void
    {
        DB::table('branches')->where('id', $this->branch->id)->update(['home_service_enabled' => true, 'home_travel_minutes' => 30,
            'home_requires_approval' => $approval, 'home_min_total' => $min, 'city_id' => $this->city->id]);
        DB::table('branch_service_areas')->updateOrInsert(['branch_id' => $this->branch->id, 'district_id' => $this->served->id],
            ['tenant_id' => $this->tenant->id, 'travel_fee' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('services')->where('id', $this->cut->id)->update(['home_available' => true]);
        DB::table('employees')->where('id', $this->ayse->id)->update(['home_service' => true]);
        $this->branch->refresh()->setRelation('tenant', $this->tenant);
    }

    private function visit(?int $district = null): HomeVisit
    {
        return new HomeVisit($district ?? $this->served->id, 'Fethiye Mah. Papatya Sk. No:5', 'Kat 3 Daire 8', 'home');
    }

    private function bookHome(string $time, ?int $employee = null, array $services = [], ?int $district = null): Appointment
    {
        return $this->book($this->at($time), $employee, $services, extra: ['homeVisit' => $this->visit($district)])['appointment'];
    }

    public function test_home_visit_stores_address_encrypted_adds_fee_and_blocks_travel_time(): void
    {
        $a = $this->bookHome('11:00', $this->ayse->id);

        $this->assertSame('address', $a->location_type);
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame(30, $a->travel_minutes);
        $this->assertSame('100.00', $a->travel_fee->toDecimal());
        $this->assertSame('400.00', $a->total_price->toDecimal());
        // 11:00 + 30 min service + 10 min buffer + 30 min travel back
        $this->assertTrue($a->blocked_until_utc->equalTo($this->at('12:10')));

        $raw = DB::table('appointment_addresses')->where('appointment_id', $a->id)->first();
        $this->assertStringNotContainsString('Papatya', $raw->address_line, 'address is encrypted at rest');
        $this->assertSame('Fethiye Mah. Papatya Sk. No:5', AppointmentAddress::withoutGlobalScopes()->find($raw->id)->address_line);

        // Travel there (10:30–11:00) and back (–12:10) are blocked for Ayşe.
        foreach (['10:15', '12:00'] as $busy) {
            try {
                $this->book($this->at($busy), $this->ayse->id, phone: '+90555000'.str_replace(':', '', $busy));
                $this->fail("{$busy} should be blocked");
            } catch (BookingConflictException) {
            }
        }
        $this->assertNotNull($this->book($this->at('09:40'), $this->ayse->id, phone: '+905550000940')['appointment']->id);
        $this->assertNotNull($this->book($this->at('12:10'), $this->ayse->id, phone: '+905550001210')['appointment']->id);
    }

    public function test_existing_salon_booking_blocks_the_travel_before_a_home_visit(): void
    {
        $this->book($this->at('10:00'), $this->ayse->id); // 10:00–10:30 + 10 buffer => free from 10:40
        $this->expectException(BookingConflictException::class);
        $this->bookHome('11:00', $this->ayse->id); // travel would start 10:30
    }

    public function test_home_slots_only_offer_home_staff_and_respect_travel(): void
    {
        $this->book($this->at('10:00'), $this->ayse->id);
        $slots = collect(app(AvailabilityService::class)->slotsForDay($this->branch, [$this->cut->id], null, '2026-11-02', homeVisit: true))
            ->map(fn ($s) => $s['start']->setTimezone('Europe/Istanbul')->format('H:i'));

        $this->assertFalse($slots->contains('11:00'));
        $this->assertTrue($slots->contains('11:15'));
        $this->assertSame([[$this->ayse->id]], collect(app(AvailabilityService::class)->slotsForDay($this->branch, [$this->cut->id], null, '2026-11-02', homeVisit: true))->pluck('employee_ids')->unique()->values()->all());
    }

    public function test_rules_area_service_staff_and_minimum(): void
    {
        $cases = [
            'outside_service_area' => fn () => $this->bookHome('11:00', district: $this->other->id),
            'service_not_home' => fn () => $this->bookHome('11:00', services: [$this->color->id]),
            'invalid_employee' => fn () => $this->bookHome('11:00', $this->mehmet->id),
        ];
        foreach ($cases as $code => $fn) {
            try {
                $fn();
                $this->fail("expected {$code}");
            } catch (DomainRuleException $e) {
                $this->assertSame($code, $e->errorCode, $e->getMessage());
            }
        }
        $this->enableHome(min: '500.00');
        try {
            $this->bookHome('11:00');
            $this->fail('expected minimum');
        } catch (DomainRuleException $e) {
            $this->assertSame('home_min_total', $e->errorCode);
        }
    }

    public function test_salon_can_require_approval_for_home_visits_only(): void
    {
        $this->enableHome(approval: true);
        $home = $this->bookHome('11:00', $this->ayse->id);
        $this->assertSame(AppointmentStatus::Pending, $home->status);
        // Pending home visit holds the travel window too.
        $this->assertTrue(DB::table('booking_holds')->where('appointment_id', $home->id)->where('starts_at_utc', $this->at('10:30'))->exists());
        $salon = $this->book($this->at('14:00'), $this->mehmet->id, phone: '+905559998877')['appointment'];
        $this->assertSame(AppointmentStatus::Confirmed, $salon->status, 'salon bookings keep the automatic mode');
    }

    public function test_reschedule_keeps_travel(): void
    {
        $a = $this->bookHome('11:00', $this->ayse->id);
        $moved = app(AppointmentService::class)->reschedule($a, $this->at('15:00'), null, Actor::system('test'));
        $this->assertTrue($moved->blocked_until_utc->equalTo($this->at('16:10')));
        $this->assertSame(30, $moved->travel_minutes);
    }

    public function test_wizard_home_flow_requires_verified_phone_and_shows_fee(): void
    {
        $wizard = fn () => Livewire::test(BookingWizard::class, ['slug' => $this->tenant->slug])
            ->assertSee('Adresimde')->call('setLocation', 'address')
            ->assertDontSee('Saç Boyama')->call('toggleService', $this->cut->id)->call('toEmployees')
            ->assertSee('Ayşe')->assertDontSee('Mehmet')->call('selectEmployee', null)
            ->call('selectTime', '11:00')->set('fullName', 'Zeynep Kaya')->set('phone', '0555 111 22 33')->set('kvkk', true)
            ->set('districtId', $this->served->id)->set('addressLine', 'Fethiye Mah. Papatya Sk. No:5');

        // Guest without a code: the server refuses (no staff sent to unverified numbers).
        $wizard()->call('toSummary')->assertSet('step', 6)->assertSee('Ulaşım')->assertSee('400,00')
            ->call('confirm')->assertSee('Telefon doğrulama kodu gerekli');
        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());

        $this->actingAs(User::factory()->create(['phone_e164' => '+905551112233', 'phone_verified_at' => now()]));
        $wizard()->call('toSummary')->call('confirm')->assertSet('step', 7);
        $a = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame('address', $a->location_type);
        $this->assertSame('400.00', $a->total_price->toDecimal());

        // Outside the served districts the form refuses before the summary.
        $wizard()->set('districtId', $this->other->id)->call('toSummary')->assertHasErrors('districtId');
    }

    public function test_panel_settings_appointment_detail_and_search(): void
    {
        $this->actingAs($this->owner($this->tenant));
        $this->put(route('tenant.branches.update', $this->branch), [
            'name' => 'Merkez', 'city_id' => $this->city->id, 'status' => 'active', 'home_form' => 1,
            'home_service_enabled' => 1, 'home_requires_approval' => 0, 'home_travel_minutes' => 20, 'home_min_total' => '250',
            'area' => [$this->served->id => ['on' => 1, 'fee' => '75,50'], $this->other->id => ['fee' => '10']],
            'extra_districts' => 'Gürsu',
            'hours' => collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => ['open' => 1, 'opens' => '09:00', 'closes' => '19:00']])->all(),
        ])->assertRedirect();
        $b = $this->branch->refresh();
        $this->assertTrue($b->home_service_enabled);
        $this->assertSame(20, $b->home_travel_minutes);
        $this->assertSame('250.00', $b->home_min_total->toDecimal());
        $this->assertEqualsCanonicalizing(['nilufer', 'gursu'], DB::table('branch_service_areas')->join('districts', 'districts.id', '=', 'branch_service_areas.district_id')->where('branch_id', $b->id)->pluck('districts.slug')->all());
        $this->assertSame('75.50', DB::table('branch_service_areas')->where('branch_id', $b->id)->where('district_id', $this->served->id)->value('travel_fee'));
        $this->get(route('tenant.branches.edit', $b))->assertOk()->assertSee('Adrese hizmet')->assertSee('Gürsu');

        $a = $this->bookHome('11:00', $this->ayse->id);
        $this->get(route('tenant.appointments.show', $a->id))->assertOk()->assertSee('Papatya Sk. No:5')->assertSee('Kat 3 Daire 8')->assertSee('Haritada aç');
        $this->get(route('tenant.appointments.index'))->assertOk()->assertSee('Adreste')->assertDontSee('Papatya');

        auth()->logout();
        // Branch has no district of its own in the served list: the salon still comes to Nilüfer.
        $this->get(route('search', ['city' => 'bursa', 'district' => 'nilufer', 'evime' => 1]))->assertOk()->assertSee($this->tenant->name)->assertSee('🏠 Adrese');
        $this->get(route('search', ['city' => 'bursa', 'district' => 'gemlik', 'evime' => 1]))->assertOk()->assertDontSee($this->tenant->name);
        $this->get(route('salon.show', $this->tenant->slug))->assertOk()->assertSee('Adresime randevu al');
    }

    public function test_old_addresses_are_purged(): void
    {
        $a = $this->bookHome('11:00', $this->ayse->id);
        $this->travelTo(now()->addDays(91));
        (new PurgeExpiredData)->handle();

        $row = AppointmentAddress::withoutGlobalScopes()->where('appointment_id', $a->id)->first();
        $this->assertNull($row->address_line);
        $this->assertNotNull($row->purged_at);
        $this->assertSame($this->served->id, $row->district_id, 'district kept for reports');
    }
}
