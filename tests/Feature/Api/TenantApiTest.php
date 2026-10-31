<?php

namespace Tests\Feature\Api;

use App\Enums\TenantRole;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    public function test_dashboard_and_appointment_actions(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/dashboard')->assertOk()->assertJsonPath('data.pending', 1);
        $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/appointments?status=pending')->assertOk()->assertJsonPath('data.0.customer.phone', '+905551112233');
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/appointments/{$a->id}/proposals", ['date' => '2026-11-02', 'time' => '16:00'])->assertCreated();
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/appointments/{$a->id}/approve")->assertStatus(409)->assertJsonPath('errors.0.code', 'invalid_transition');
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/appointments/{$a->id}/cancel", ['reason' => 'Müşteri aradı'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_staff_booking_complete_and_payments(): void
    {
        $this->buildSalon('manual');
        $owner = $this->owner($this->tenant);
        $r = $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/appointments', [
            'branch_id' => $this->branch->id, 'service_ids' => [$this->cut->id], 'date' => '2026-11-02', 'time' => '09:00',
            'full_name' => 'Telefon Müşterisi', 'phone' => '05321112233',
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.source', 'panel');
        $id = $r->json('data.id');
        $this->travelTo($this->at('09:40'));
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/appointments/{$id}/complete", ['discount' => '30', 'payments' => [['amount' => '270.00', 'method' => 'cash']]])
            ->assertOk()->assertJsonPath('data.total.amount', '270.00')->assertJsonPath('data.payments.0.method', 'cash');
        $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/reports')->assertOk()->assertJsonPath('data.revenue_completed.amount', '270.00');
    }

    public function test_branch_and_employee_limits_enforced_by_backend(): void
    {
        $this->buildSalon('automatic', 'standart'); // 1 branch, 2 employees (already used)
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/branches', ['name' => 'İkinci Şube'])->assertForbidden()->assertJsonPath('errors.0.code', 'plan_limit');
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/employees', ['display_name' => 'Üçüncü', 'branch_ids' => [$this->branch->id]])->assertForbidden()->assertJsonPath('errors.0.limit', 'max_employees');
        // Deactivating one frees a seat; inactive employees can still be created.
        $this->api($owner, $this->tenant)->patchJson("/api/v1/tenant/employees/{$this->mehmet->id}", ['status' => 'inactive'])->assertOk();
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/employees', ['display_name' => 'Yeni', 'branch_ids' => [$this->branch->id], 'service_ids' => [$this->cut->id]])->assertCreated();
    }

    public function test_service_crud_and_branch_price_override(): void
    {
        $this->buildSalon();
        $owner = $this->owner($this->tenant);
        $id = $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/services', [
            'name' => 'Keratin', 'duration_minutes' => 120, 'buffer_minutes' => 15, 'base_price' => '2500,00',
            'branch_ids' => [$this->branch->id], 'branch_overrides' => [['branch_id' => $this->branch->id, 'price' => '2250']],
        ])->assertCreated()->assertJsonPath('data.price.amount', '2500.00')->json('data.id');
        $this->getJson("/api/v1/branches/{$this->branch->id}/services")->assertJsonFragment(['amount' => '2250.00']);
        $this->api($owner, $this->tenant)->deleteJson("/api/v1/tenant/services/{$id}")->assertOk();
        $this->getJson("/api/v1/branches/{$this->branch->id}/services")->assertJsonMissing(['name' => 'Keratin']);
    }

    public function test_booking_settings_and_listing_toggle(): void
    {
        $this->buildSalon();
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->patchJson('/api/v1/tenant/booking-settings', ['approval_mode' => 'manual', 'slot_interval_minutes' => 30])
            ->assertOk()->assertJsonPath('data.slot_interval_minutes', 30);
        $this->api($owner, $this->tenant)->patchJson('/api/v1/tenant/settings', ['public_listing_enabled' => false])->assertOk();
        $this->getJson('/api/v1/salons')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/salons/'.$this->tenant->slug)->assertOk(); // direct link still works
    }

    public function test_receptionist_permissions(): void
    {
        $this->buildSalon('manual');
        $rec = $this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]);
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->api($rec, $this->tenant)->postJson("/api/v1/tenant/appointments/{$a->id}/approve")->assertOk();
        $this->api($rec, $this->tenant)->postJson('/api/v1/tenant/services', ['name' => 'X', 'duration_minutes' => 30, 'base_price' => '1'])->assertForbidden();
        $this->api($rec, $this->tenant)->patchJson('/api/v1/tenant/booking-settings', ['approval_mode' => 'automatic'])->assertForbidden();
        $this->api($rec, $this->tenant)->postJson('/api/v1/tenant/subscription/change-plan', ['plan' => 'premium', 'interval' => 'monthly'])->assertForbidden();
    }

    public function test_employee_sees_only_own_appointments(): void
    {
        $this->buildSalon('automatic');
        $user = User::factory()->create();
        $this->ayse->forceFill(['user_id' => $user->id])->save();
        app(TenantProvisioner::class)->addMember($this->tenant, $user, TenantRole::Employee, [$this->branch->id]);
        $own = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $other = $this->book($this->at('10:00'), $this->mehmet->id, phone: '+905559990000')['appointment'];
        $ids = collect($this->api($user, $this->tenant)->getJson('/api/v1/tenant/appointments')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$own->id], $ids->all());
        $this->api($user, $this->tenant)->getJson("/api/v1/tenant/appointments/{$other->id}")->assertForbidden();
        $this->api($user, $this->tenant)->postJson("/api/v1/tenant/appointments/{$own->id}/cancel")->assertForbidden();
    }

    public function test_n_plus_one_guard_on_appointment_list(): void
    {
        $this->buildSalon('automatic');
        $owner = $this->owner($this->tenant);
        $count = function () use ($owner) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/appointments')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->book($this->at('10:00'), $this->ayse->id);
        $count(); // warm-up: permission cache, tenant memo
        $few = $count();
        foreach (['11:00', '12:00', '13:00', '14:00', '15:00'] as $i => $t) {
            $this->book($this->at($t), $i % 2 ? $this->ayse->id : $this->mehmet->id, phone: '+90555000100'.$i);
        }
        $this->assertSame($few, $count(), 'query count must not grow with rows');
        $this->assertSame(6, Appointment::withoutGlobalScopes()->count());
    }
}
