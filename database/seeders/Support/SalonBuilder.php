<?php

namespace Database\Seeders\Support;

use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fluent builder for a fully configured salon (tenant, branches, hours,
 * services, employees). Used by demo seeders and the test-suite.
 */
final class SalonBuilder
{
    private ?Tenant $tenant = null;

    public function __construct(private readonly TenantProvisioner $provisioner, private readonly TenantContext $context) {}

    public static function make(): self
    {
        return app(self::class);
    }

    public function tenant(string $name, Plan $plan, ?User $owner = null, array $attrs = []): self
    {
        $owner ??= User::factory()->create(['name' => $name.' Sahibi']);
        $this->tenant = $this->provisioner->provision($owner, $name, $plan, $attrs);

        return $this;
    }

    /** Continue building on an existing tenant (e.g. add services to an earlier showcase salon). */
    public function forTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function get(): Tenant
    {
        return $this->tenant->refresh();
    }

    /** @param array<int, array{0:string,1:string}>|null $hours day_of_week => [open, close] (local) */
    public function branch(string $name, array $attrs = [], ?array $hours = null): Branch
    {
        return $this->context->run($this->tenant, function () use ($name, $attrs, $hours) {
            $branch = Branch::create(array_merge([
                'name' => $name,
                'slug' => Str::slug($name),
                'timezone' => 'Europe/Istanbul',
                'status' => 'active',
                'phone' => '+902121234567',
            ], $attrs));
            $hours ??= [1 => ['09:00', '19:00'], 2 => ['09:00', '19:00'], 3 => ['09:00', '19:00'], 4 => ['09:00', '19:00'], 5 => ['09:00', '19:00'], 6 => ['10:00', '18:00'], 0 => ['10:00', '18:00']];
            foreach ($hours as $dow => [$open, $close]) {
                DB::table('working_hours')->insert([
                    'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'employee_id' => null,
                    'day_of_week' => $dow, 'starts_local' => $open, 'ends_local' => $close, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $branch;
        });
    }

    /** @param list<Branch> $branches */
    public function service(string $name, int $minutes, string $price, array $branches, int $buffer = 0, ?string $categorySlug = null): Service
    {
        return $this->context->run($this->tenant, function () use ($name, $minutes, $price, $branches, $buffer, $categorySlug) {
            $category = $categorySlug ? ServiceCategory::whereNull('tenant_id')->where('slug', $categorySlug)->first() : null;
            $service = Service::create([
                'name' => $name, 'duration_minutes' => $minutes, 'buffer_minutes' => $buffer,
                'base_price' => $price, 'currency' => 'TRY', 'active' => true, 'category_id' => $category?->id,
            ]);
            foreach ($branches as $b) {
                DB::table('branch_services')->insert([
                    'tenant_id' => $this->tenant->id, 'branch_id' => $b->id, 'service_id' => $service->id,
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->refreshMinPrice();

            return $service;
        });
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<Service>  $services
     */
    public function employee(string $name, array $branches, array $services, ?User $user = null, TenantRole $role = TenantRole::Employee): Employee
    {
        return $this->context->run($this->tenant, function () use ($name, $branches, $services, $user, $role) {
            $employee = Employee::create(['display_name' => $name, 'status' => 'active', 'user_id' => $user?->id]);
            foreach ($branches as $b) {
                DB::table('employee_branches')->insert([
                    'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'branch_id' => $b->id,
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($services as $s) {
                DB::table('employee_services')->insert([
                    'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'service_id' => $s->id,
                    'branch_id' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($user) {
                $this->provisioner->addMember($this->tenant, $user, $role, array_map(fn ($b) => $b->id, $branches));
            }

            return $employee;
        });
    }

    /** @param list<Branch> $branches */
    public function staff(User $user, TenantRole $role, array $branches = []): User
    {
        $this->provisioner->addMember($this->tenant, $user, $role, array_map(fn ($b) => $b->id, $branches));

        return $user;
    }

    public function settings(array $values): self
    {
        app(SettingsRepository::class)->setTenant($this->tenant, $values);

        return $this;
    }

    private function refreshMinPrice(): void
    {
        $min = DB::table('services')->where('tenant_id', $this->tenant->id)->where('active', true)->whereNull('deleted_at')->min('base_price');
        DB::table('tenants')->where('id', $this->tenant->id)->update(['min_service_price' => $min]);
    }
}
