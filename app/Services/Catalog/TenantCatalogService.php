<?php

namespace App\Services\Catalog;

use App\Enums\RecordStatus;
use App\Enums\TenantRole;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Settings\SettingsRepository;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Branch / employee / service management inside the current tenant.
 * Plan limits are enforced here (backend), not only in the UI.
 */
final class TenantCatalogService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly SettingsRepository $settings,
        private readonly TenantProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    private function tenant(): Tenant
    {
        return $this->context->require();
    }

    public function saveBranch(?Branch $branch, array $data): Branch
    {
        $tenant = $this->tenant();
        $activating = ($data['status'] ?? 'active') === 'active' && (! $branch || $branch->status !== RecordStatus::Active);

        return DB::transaction(function () use ($branch, $data, $tenant, $activating) {
            if ($activating) {
                // Lock the tenant row so two concurrent creates cannot both pass the limit check.
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $this->entitlements->assertCanAddBranch($tenant);
            }
            $branch ??= new Branch(['tenant_id' => $tenant->id]);
            $attrs = collect($data)->only(['name', 'phone', 'email', 'address', 'city_id', 'district_id', 'lat', 'lng', 'timezone', 'status'])->all();
            if (! $branch->exists || isset($data['slug'])) {
                $attrs['slug'] = $this->uniqueBranchSlug($tenant, $data['slug'] ?? $data['name'] ?? $branch->name, $branch->id);
            }
            $attrs['timezone'] ??= $branch->timezone ?? $tenant->timezone;
            $branch->forceFill($attrs + ['tenant_id' => $tenant->id])->save();

            if (array_key_exists('working_hours', $data)) {
                $this->syncHours($branch, null, $data['working_hours']);
            }
            if (array_key_exists('whatsapp_manager_numbers', $data)) {
                $numbers = array_values(array_filter(array_map(fn ($n) => PhoneNumber::normalize((string) $n), (array) $data['whatsapp_manager_numbers'])));
                $this->settings->setBranch($branch, ['whatsapp_manager_numbers' => $numbers]);
            }
            $this->audit->log($branch->wasRecentlyCreated ? 'branch.created' : 'branch.updated', $branch, [], $attrs);

            return $branch;
        });
    }

    /** Soft delete; future appointments must be handled first. */
    public function deleteBranch(Branch $branch): void
    {
        $future = DB::table('appointments')->where('branch_id', $branch->id)->whereIn('status', ['pending', 'confirmed', 'alternative_proposed'])
            ->where('starts_at_utc', '>', now())->count();
        if ($future > 0) {
            throw new DomainRuleException("Bu şubede {$future} gelecek randevu var. Önce randevuları iptal edin veya taşıyın.", 'branch_has_appointments');
        }
        $branch->forceFill(['status' => RecordStatus::Inactive])->save();
        $branch->delete();
        $this->audit->log('branch.deleted', $branch);
    }

    /** @param list<array{day_of_week:int, opens:string, closes:string, branch_id?:int}> $rows */
    public function syncHours(Branch $branch, ?Employee $employee, array $rows): void
    {
        DB::table('working_hours')->where('branch_id', $branch->id)
            ->when($employee, fn ($q) => $q->where('employee_id', $employee->id), fn ($q) => $q->whereNull('employee_id'))->delete();
        foreach ($rows as $row) {
            if ($row['closes'] <= $row['opens']) {
                throw new DomainRuleException('Kapanış saati açılıştan sonra olmalıdır.', 'invalid_hours');
            }
            DB::table('working_hours')->insert([
                'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'employee_id' => $employee?->id,
                'day_of_week' => (int) $row['day_of_week'], 'starts_local' => $row['opens'], 'ends_local' => $row['closes'],
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function saveService(?Service $service, array $data): Service
    {
        $tenant = $this->tenant();

        return DB::transaction(function () use ($service, $data, $tenant) {
            $service ??= new Service(['tenant_id' => $tenant->id]);
            if (isset($data['category_id'])) {
                $ok = DB::table('service_categories')->where('id', $data['category_id'])->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))->exists();
                if (! $ok) {
                    throw new DomainRuleException('Geçersiz kategori.', 'invalid_category');
                }
            }
            $attrs = collect($data)->only(['name', 'description', 'category_id', 'duration_minutes', 'buffer_minutes', 'base_price', 'active'])->all();
            $service->forceFill($attrs + ['tenant_id' => $tenant->id, 'currency' => 'TRY'])->save();

            if (array_key_exists('branch_ids', $data)) {
                $ids = array_map('intval', (array) $data['branch_ids']);
                DB::table('branch_services')->where('service_id', $service->id)->whereNotIn('branch_id', $ids ?: [0])->delete();
                foreach ($ids as $bid) {
                    DB::table('branch_services')->updateOrInsert(['branch_id' => $bid, 'service_id' => $service->id],
                        ['tenant_id' => $tenant->id, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            foreach ($data['branch_overrides'] ?? [] as $o) {
                DB::table('branch_services')->where('service_id', $service->id)->where('branch_id', (int) $o['branch_id'])->where('tenant_id', $tenant->id)
                    ->update([
                        'price_override' => isset($o['price']) && $o['price'] !== '' ? Money::parse((string) $o['price'])->toDecimal() : null,
                        'duration_override' => $o['duration'] ?? null, 'updated_at' => now(),
                    ]);
            }
            $this->refreshMinPrice($tenant);

            return $service;
        });
    }

    public function deleteService(Service $service): void
    {
        $service->forceFill(['active' => false])->save();
        $service->delete();
        $this->refreshMinPrice($this->tenant());
    }

    public function saveEmployee(?Employee $employee, array $data): Employee
    {
        $tenant = $this->tenant();
        $activating = ($data['status'] ?? 'active') === 'active' && (! $employee || $employee->status !== RecordStatus::Active);

        return DB::transaction(function () use ($employee, $data, $tenant, $activating) {
            if ($activating) {
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $this->entitlements->assertCanAddEmployee($tenant);
            }
            $employee ??= new Employee(['tenant_id' => $tenant->id]);
            $employee->forceFill(collect($data)->only(['display_name', 'bio', 'status', 'buffer_minutes'])->all() + ['tenant_id' => $tenant->id])->save();

            if (isset($data['photo']) && $data['photo'] instanceof UploadedFile) {
                $employee->forceFill(['photo' => $this->storeImage($data['photo'], "tenants/{$tenant->id}/employees")])->save();
            }
            if (array_key_exists('branch_ids', $data)) {
                $ids = array_map('intval', $data['branch_ids']);
                DB::table('employee_branches')->where('employee_id', $employee->id)->whereNotIn('branch_id', $ids ?: [0])->delete();
                foreach ($ids as $bid) {
                    DB::table('employee_branches')->updateOrInsert(['employee_id' => $employee->id, 'branch_id' => $bid],
                        ['tenant_id' => $tenant->id, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            if (array_key_exists('service_ids', $data)) {
                $ids = array_map('intval', $data['service_ids']);
                DB::table('employee_services')->where('employee_id', $employee->id)->whereNull('branch_id')->whereNotIn('service_id', $ids ?: [0])->delete();
                foreach ($ids as $sid) {
                    DB::table('employee_services')->updateOrInsert(['employee_id' => $employee->id, 'service_id' => $sid, 'branch_id' => null],
                        ['tenant_id' => $tenant->id, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            if (array_key_exists('shifts', $data)) {
                DB::table('working_hours')->where('employee_id', $employee->id)->delete();
                foreach ($data['shifts'] as $s) {
                    $branch = Branch::findOrFail((int) $s['branch_id']); // tenant-scoped lookup
                    if ($s['closes'] <= $s['opens']) {
                        throw new DomainRuleException('Vardiya bitişi başlangıçtan sonra olmalıdır.', 'invalid_hours');
                    }
                    DB::table('working_hours')->insert([
                        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'employee_id' => $employee->id,
                        'day_of_week' => (int) $s['day_of_week'], 'starts_local' => $s['opens'], 'ends_local' => $s['closes'],
                        'active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if (! empty($data['user_email'])) {
                $user = User::where('email', strtolower($data['user_email']))->first();
                if (! $user) {
                    throw new DomainRuleException('Bu e-posta ile kayıtlı kullanıcı bulunamadı. Çalışanın önce hesap açması gerekir.', 'user_not_found');
                }
                $role = TenantRole::tryFrom($data['role'] ?? 'employee') ?? TenantRole::Employee;
                if ($role === TenantRole::Owner) {
                    throw new DomainRuleException('Sahip rolü atanamaz.', 'invalid_role');
                }
                $employee->forceFill(['user_id' => $user->id])->save();
                $this->provisioner->addMember($tenant, $user, $role, $data['branch_ids'] ?? DB::table('employee_branches')->where('employee_id', $employee->id)->pluck('branch_id')->all());
            }
            $this->audit->log($employee->wasRecentlyCreated ? 'employee.created' : 'employee.updated', $employee);

            return $employee;
        });
    }

    public function deleteEmployee(Employee $employee): void
    {
        $future = DB::table('appointments')->where('employee_id', $employee->id)->whereIn('status', ['pending', 'confirmed', 'alternative_proposed'])
            ->where('starts_at_utc', '>', now())->count();
        if ($future > 0) {
            throw new DomainRuleException("Bu personelin {$future} gelecek randevusu var. Önce randevuları başka personele taşıyın.", 'employee_has_appointments');
        }
        $employee->forceFill(['status' => RecordStatus::Inactive])->save();
        $employee->delete();
        if ($employee->user_id && ($user = User::find($employee->user_id))) {
            $this->provisioner->removeMember($this->tenant(), $user);
        }
        $this->audit->log('employee.deleted', $employee);
    }

    /**
     * Uploaded images: validated mime/size upstream; here re-encoded to strip
     * metadata/payloads and stored under a random name on the public disk.
     */
    public function storeImage(UploadedFile $file, string $dir): string
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || @getimagesize($file->getRealPath()) === false) {
            throw new DomainRuleException('Geçersiz görsel dosyası.', 'invalid_image');
        }
        $name = $dir.'/'.Str::random(40).'.jpg';
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring((string) file_get_contents($file->getRealPath())))) {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, 1600 / max($w, $h));
            $out = imagecreatetruecolor((int) ($w * $scale), (int) ($h * $scale));
            imagecopyresampled($out, $img, 0, 0, 0, 0, (int) ($w * $scale), (int) ($h * $scale), $w, $h);
            ob_start();
            imagejpeg($out, null, 85);
            Storage::disk('public')->put($name, (string) ob_get_clean());
            imagedestroy($img);
            imagedestroy($out);
        } else {
            throw new DomainRuleException('Görsel işlenemedi.', 'invalid_image');
        }

        return $name;
    }

    public function refreshMinPrice(Tenant $tenant): void
    {
        $min = DB::table('services')->where('tenant_id', $tenant->id)->where('active', true)->whereNull('deleted_at')->min('base_price');
        DB::table('tenants')->where('id', $tenant->id)->update(['min_service_price' => $min]);
    }

    private function uniqueBranchSlug(Tenant $tenant, string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'sube';
        $slug = $base;
        $i = 2;
        while (DB::table('branches')->where('tenant_id', $tenant->id)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
