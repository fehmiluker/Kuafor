<?php

namespace Tests\Security;

use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\TenantRole;
use App\Exceptions\TenantAccessDeniedException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private Tenant $other;

    private Branch $otherBranch;

    private Appointment $otherAppointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon('automatic');
        $b = SalonBuilder::make()->tenant('Rakip Salon', $this->plan());
        $this->otherBranch = $b->branch('Rakip Şube');
        $svc = $b->service('Rakip Kesim', 30, '100.00', [$this->otherBranch]);
        $emp = $b->employee('Rakip', [$this->otherBranch], [$svc]);
        $this->other = $b->get();
        $this->otherAppointment = app(AppointmentService::class)->create($this->other, new CreateAppointmentData(
            $this->otherBranch->id, [$svc->id], $emp->id, $this->at('10:00'), 'Gizli Müşteri', '+905550009999'))['appointment'];
    }

    public function test_tenant_a_cannot_read_tenant_b_records_by_id(): void
    {
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->getJson("/api/v1/tenant/appointments/{$this->otherAppointment->id}")->assertNotFound();
        $this->api($owner, $this->tenant)->getJson("/api/v1/tenant/branches/{$this->otherBranch->id}")->assertNotFound();
        $this->api($owner, $this->tenant)->getJson("/api/v1/tenant/customers/{$this->otherAppointment->customer_id}")->assertNotFound();
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/appointments/{$this->otherAppointment->id}/cancel")->assertNotFound();
        $list = $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/customers')->json('data');
        $this->assertNotContains('Gizli Müşteri', array_column($list, 'full_name'));
        $this->actingAs($owner)->get("/panel/randevular/{$this->otherAppointment->id}")->assertNotFound();
    }

    public function test_cannot_switch_into_a_tenant_without_membership(): void
    {
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->other)->getJson('/api/v1/tenant/dashboard')->assertForbidden()->assertJsonPath('errors.0.code', 'tenant_required');
        $this->flushHeaders();
        $this->actingAs($owner)->post('/panel/isletme-sec', ['tenant_id' => $this->other->id])->assertForbidden();
        $this->actingAs($owner)->withSession(['current_tenant_id' => $this->other->id])->get('/panel')->assertRedirect(route('tenant.select'));
    }

    public function test_forged_foreign_ids_are_rejected(): void
    {
        $owner = $this->owner($this->tenant);
        $foreignService = Service::withoutGlobalScopes()->where('tenant_id', $this->other->id)->first();
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/appointments', [
            'branch_id' => $this->otherBranch->id, 'service_ids' => [$this->cut->id], 'date' => '2026-11-02', 'time' => '12:00', 'full_name' => 'X', 'phone' => '05551112233',
        ])->assertStatus(422)->assertJsonPath('errors.0.field', 'branch_id');
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/appointments', [
            'branch_id' => $this->branch->id, 'service_ids' => [$foreignService->id], 'date' => '2026-11-02', 'time' => '12:00', 'full_name' => 'X', 'phone' => '05551112233',
        ])->assertStatus(422);
        $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/employees', ['display_name' => 'Casus', 'branch_ids' => [$this->otherBranch->id]])->assertStatus(422);
        // Public booking: employee from another tenant is not a candidate.
        $this->flushHeaders();
        $this->postJson('/api/v1/appointments', ['branch_id' => $this->branch->id, 'service_ids' => [$this->cut->id], 'employee_id' => $this->otherAppointment->employee_id,
            'date' => '2026-11-02', 'time' => '12:00', 'full_name' => 'Ali Veli', 'phone' => '05551112233', 'kvkk_acknowledged' => true])->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_employee');
        // A tenant_id smuggled into the payload is ignored.
        $id = $this->api($owner, $this->tenant)->postJson('/api/v1/tenant/customers', ['full_name' => 'Yeni', 'phone' => '05447778899', 'tenant_id' => $this->other->id])->assertCreated()->json('data.id');
        $this->assertSame($this->tenant->id, (int) DB::table('tenant_customers')->where('id', $id)->value('tenant_id'));
    }

    public function test_global_scope_fails_closed_without_context(): void
    {
        app(TenantContext::class)->forget();
        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, TenantCustomer::count());
        $this->assertSame(2, app(TenantContext::class)->asPlatform(fn () => Appointment::count()) >= 1 ? 2 : 0);
        $this->assertSame(1, $this->inTenant($this->other, fn () => Appointment::count()));
    }

    public function test_model_hooks_block_cross_tenant_writes(): void
    {
        $this->expectException(TenantAccessDeniedException::class);
        $this->inTenant($this->tenant, fn () => TenantCustomer::create(['tenant_id' => $this->other->id, 'full_name' => 'X', 'phone_e164' => '+905550000000']));
    }

    public function test_tenant_id_cannot_be_changed(): void
    {
        $this->expectException(TenantAccessDeniedException::class);
        $this->book($this->at('12:00'));
        $this->inTenant($this->tenant, function () {
            $c = TenantCustomer::firstOrFail();
            $c->tenant_id = $this->other->id;
            $c->save();
        });
    }

    public function test_composite_foreign_keys_block_cross_tenant_rows_at_db_level(): void
    {
        $this->expectException(QueryException::class);
        // Branch of tenant B referenced with tenant A's id: rejected by (tenant_id, branch_id) FK.
        DB::table('branch_services')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'service_id' => $this->cut->id, 'active' => 1]);
    }

    public function test_branch_manager_is_limited_to_assigned_branch(): void
    {
        $second = $this->inTenant($this->tenant, fn () => $this->builder->branch('İkinci'));
        \DB::table('branch_services')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $second->id, 'service_id' => $this->cut->id, 'active' => 1]);
        \DB::table('employee_branches')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $second->id, 'employee_id' => $this->mehmet->id, 'active' => 1]);
        $inSecond = app(AppointmentService::class)->create($this->tenant, new CreateAppointmentData(
            $second->id, [$this->cut->id], $this->mehmet->id, $this->at('16:00'), 'Şube2 Müşteri', '+905550001111'))['appointment'];
        $inFirst = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $manager = $this->member($this->tenant, TenantRole::BranchManager, [$this->branch->id]);

        $this->api($manager, $this->tenant)->getJson("/api/v1/tenant/appointments/{$inSecond->id}")->assertForbidden();
        $this->api($manager, $this->tenant)->getJson("/api/v1/tenant/appointments/{$inFirst->id}")->assertOk();
        $ids = collect($this->api($manager, $this->tenant)->getJson('/api/v1/tenant/appointments')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inFirst->id));
        $this->assertFalse($ids->contains($inSecond->id));
        $this->api($manager, $this->tenant)->getJson("/api/v1/tenant/branches/{$second->id}")->assertForbidden();
        $this->api($manager, $this->tenant)->patchJson("/api/v1/tenant/branches/{$second->id}", ['name' => 'Ele geçirildi'])->assertForbidden();
        $this->actingAs($manager)->get('/panel/takvim/olaylar?start=2026-11-01&end=2026-11-08&branch_id='.$second->id)->assertForbidden();
    }

    public function test_customer_cannot_access_other_customers_appointments(): void
    {
        $user = User::factory()->create(['phone_e164' => '+905557776655', 'phone_verified_at' => now()]);
        $this->api($user)->postJson("/api/v1/me/appointments/{$this->otherAppointment->id}/cancel")->assertNotFound();
        $this->api($user)->postJson("/api/v1/appointments/{$this->otherAppointment->id}/reviews", ['rating' => 1])->assertNotFound();
        $this->api($user)->getJson('/api/v1/me/appointments')->assertOk()->assertJsonCount(0, 'data');
    }
}
