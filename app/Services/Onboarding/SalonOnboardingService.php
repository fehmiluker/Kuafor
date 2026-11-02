<?php

namespace App\Services\Onboarding;

use App\Enums\BillingInterval;
use App\Enums\PlatformRole;
use App\Exceptions\DomainRuleException;
use App\Models\District;
use App\Models\Plan;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SalonInvitation;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\TenantCatalogService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a complete salon on behalf of its owner (platform admin: bulk import
 * or an approved "Salonunu ekle" application). The owner account has no
 * password until the owner accepts the invitation link (7-day "invites" broker).
 * Only for salons that agreed to be listed — never for scraped businesses.
 */
final class SalonOnboardingService
{
    /** Default opening hours (local): Mon–Sat 09:00–19:00, Sunday closed. */
    public const DEFAULT_HOURS = [1 => ['09:00', '19:00'], 2 => ['09:00', '19:00'], 3 => ['09:00', '19:00'], 4 => ['09:00', '19:00'], 5 => ['09:00', '19:00'], 6 => ['09:00', '19:00']];

    /** Keyword => platform category slug, used when a service row has no explicit category. */
    private const CATEGORY_KEYWORDS = [
        'masaj' => 'masaj', 'gelin' => 'gelin', 'nişan' => 'gelin', 'kına' => 'gelin',
        'sakal' => 'sakal', 'tıraş' => 'sakal', 'ustura' => 'sakal',
        'boya' => 'sac-boya', 'balyaj' => 'sac-boya', 'röfle' => 'sac-boya', 'ombre' => 'sac-boya', 'keratin' => 'sac-boya',
        'fön' => 'fon-sekillendirme', 'topuz' => 'fon-sekillendirme', 'maşa' => 'fon-sekillendirme', 'şekillendirme' => 'fon-sekillendirme',
        'manikür' => 'manikur-pedikur', 'pedikür' => 'manikur-pedikur', 'oje' => 'manikur-pedikur', 'tırnak' => 'manikur-pedikur',
        'cilt' => 'cilt-bakimi', 'hydrafacial' => 'cilt-bakimi',
        'makyaj' => 'makyaj', 'kaş' => 'kas-kirpik', 'kirpik' => 'kas-kirpik',
        'ağda' => 'epilasyon', 'epilasyon' => 'epilasyon', 'lazer' => 'epilasyon',
        'kesim' => 'sac-kesim', 'saç' => 'sac-kesim',
    ];

    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantContext $context,
        private readonly TenantCatalogService $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{business_name:string, owner_name:string, owner_email:string, owner_phone:?string, city_id:int, district_id:?int,
     *     district_name?:?string, address:string, plan:string, description?:?string, services?:list<array{name:string, minutes:int, price:string, category:?string}>,
     *     employees?:list<string>} $d
     * @return array{tenant: Tenant, user: User, new_user: bool}
     */
    public function create(array $d, string $source): array
    {
        $plan = Plan::with('features')->where('slug', $d['plan'])->where('active', true)->first()
            ?? throw new DomainRuleException("Paket bulunamadı: {$d['plan']}", 'invalid_plan');
        $email = strtolower(trim($d['owner_email']));
        $services = $d['services'] ?? [];

        return DB::transaction(function () use ($d, $plan, $email, $services, $source) {
            $d['district_id'] ??= self::resolveDistrict((int) $d['city_id'], $d['district_name'] ?? null);
            $user = User::where('email', $email)->first();
            $newUser = ! $user;
            if ($user && Tenant::withTrashed()->where('owner_user_id', $user->id)->where('name', $d['business_name'])->exists()) {
                throw new DomainRuleException('Bu e-postaya ait aynı isimde bir işletme zaten var.', 'duplicate_salon');
            }
            if (! $user) {
                $phoneFree = $d['owner_phone'] && ! User::where('phone_e164', $d['owner_phone'])->exists();
                $user = new User;
                $user->forceFill(['name' => $d['owner_name'], 'email' => $email, 'phone_e164' => $phoneFree ? $d['owner_phone'] : null,
                    'password' => null, 'locale' => 'tr', 'status' => 'active'])->save();
                $user->inTeam((int) config('tenancy.platform_team_id'), fn (User $u) => $u->assignRole(PlatformRole::Customer->value));
            }

            // Listed only once it has something to book; otherwise the owner adds services and opens listing from the panel.
            $tenant = $this->provisioner->provision($user, $d['business_name'], $plan, array_filter([
                'description' => $d['description'] ?? null,
                'public_listing_enabled' => $services !== [],
            ], fn ($v) => $v !== null), BillingInterval::Monthly);

            $this->context->run($tenant, function () use ($d, $services) {
                $branch = $this->catalog->saveBranch(null, [
                    'name' => 'Merkez', 'address' => $d['address'], 'city_id' => $d['city_id'], 'district_id' => $d['district_id'] ?? null,
                    'phone' => $d['owner_phone'], 'status' => 'active',
                    'working_hours' => array_map(fn ($day, $h) => ['day_of_week' => $day, 'opens' => $h[0], 'closes' => $h[1]], array_keys(self::DEFAULT_HOURS), self::DEFAULT_HOURS),
                ]);
                $serviceIds = [];
                foreach ($services as $s) {
                    $slug = $s['category'] ?? self::guessCategory($s['name']);
                    $serviceIds[] = $this->catalog->saveService(null, [
                        'name' => $s['name'], 'duration_minutes' => $s['minutes'], 'buffer_minutes' => 0, 'base_price' => $s['price'], 'active' => true,
                        'category_id' => $slug ? ServiceCategory::whereNull('tenant_id')->where('slug', $slug)->value('id') : null,
                        'branch_ids' => [$branch->id],
                    ])->id;
                }
                foreach ($d['employees'] ?? [] as $name) {
                    $this->catalog->saveEmployee(null, ['display_name' => $name, 'status' => 'active', 'branch_ids' => [$branch->id], 'service_ids' => $serviceIds]);
                }
            });
            $this->audit->log('salon.onboarded', $tenant, [], ['source' => $source, 'services' => count($services), 'employees' => count($d['employees'] ?? [])], tenantId: $tenant->id);

            return ['tenant' => $tenant->refresh(), 'user' => $user, 'new_user' => $newUser];
        });
    }

    /** Invitation e-mail: a 7-day link to set a password (new owners) or just a panel link (existing accounts). */
    public function invite(User $user, Tenant $tenant): void
    {
        $token = $user->password === null ? Password::broker('invites')->createToken($user) : null;
        $user->notify(new SalonInvitation($tenant, $token));
    }

    /** District by name within the city; unknown names are added (admin-reviewed data only). */
    public static function resolveDistrict(int $cityId, ?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '' || ! ($slug = Str::slug($name))) {
            return null;
        }

        return District::firstOrCreate(['city_id' => $cityId, 'slug' => $slug], ['name' => Str::limit($name, 100, '')])->id;
    }

    public static function guessCategory(string $serviceName): ?string
    {
        $name = Str::lower($serviceName);
        foreach (self::CATEGORY_KEYWORDS as $keyword => $slug) {
            if (str_contains($name, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
}
