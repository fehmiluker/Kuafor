<?php

namespace Database\Seeders;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\TenantRole;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentCompleted;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\ProposalCreated;
use App\Models\Appointment;
use App\Models\City;
use App\Models\District;
use App\Models\Plan;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Booking\AppointmentService;
use App\Services\Notifications\ConsentService;
use App\Services\Reviews\ReviewService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Demo data: 3 tenants on 3 plans, a multi-branch salon, employees, services,
 * appointments in every state and verified reviews. NEVER run in production —
 * demo users share the password "Demo12345!" and are listed in README.md.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo12345!';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder production ortamında çalıştırılamaz.');

            return;
        }
        // Only domain events are faked (a bare Event::fake() would also swallow Eloquent model events).
        Event::fake([AppointmentCreated::class, AppointmentConfirmed::class, AppointmentCompleted::class,
            ProposalCreated::class, AppointmentCancelled::class]);
        $istanbul = City::where('slug', 'istanbul')->first();
        $ankara = City::where('slug', 'ankara')->first();
        $izmir = City::where('slug', 'izmir')->first();
        $d = fn ($city, $slug) => District::where('city_id', $city->id)->where('slug', $slug)->value('id');

        // 1) Premium, multi-branch, manual approval, WhatsApp (sandbox) connected
        $owner1 = $this->user('Elif Yıldız', 'sahip@isiltikuafor.test', '+905321110001');
        $b = SalonBuilder::make()->tenant('Işıltı Kuaför', Plan::with('features')->where('slug', 'premium')->first(), $owner1, ['description' => 'Kadıköy ve Beşiktaş\'ta 15 yıllık deneyimle saç, renk ve bakım hizmetleri.']);
        $b->settings(['approval_mode' => 'manual', 'min_notice_minutes' => 60]);
        $kadikoy = $b->branch('Kadıköy', ['city_id' => $istanbul->id, 'district_id' => $d($istanbul, 'kadikoy'), 'address' => 'Caferağa Mah. Moda Cad. No:10', 'lat' => 40.9870, 'lng' => 29.0260]);
        $besiktas = $b->branch('Beşiktaş', ['city_id' => $istanbul->id, 'district_id' => $d($istanbul, 'besiktas'), 'address' => 'Sinanpaşa Mah. Ihlamurdere Cad. No:5', 'lat' => 41.0430, 'lng' => 29.0070]);
        $cut = $b->service('Kadın Saç Kesimi', 45, '650.00', [$kadikoy, $besiktas], 10, 'sac-kesim');
        $blow = $b->service('Fön', 30, '350.00', [$kadikoy, $besiktas], 5, 'fon-sekillendirme');
        $color = $b->service('Dip Boya', 90, '1800.00', [$kadikoy, $besiktas], 15, 'sac-boya');
        $mani = $b->service('Manikür', 40, '450.00', [$kadikoy], 5, 'manikur-pedikur');
        $manager = $this->user('Can Demir', 'mudur@isiltikuafor.test', '+905321110002');
        $reception = $this->user('Seda Kaya', 'resepsiyon@isiltikuafor.test', '+905321110003');
        $b->staff($manager, TenantRole::BranchManager, [$kadikoy]);
        $b->staff($reception, TenantRole::Receptionist, [$kadikoy, $besiktas]);
        $ayse = $b->employee('Ayşe', [$kadikoy], [$cut, $blow, $color], $this->user('Ayşe Usta', 'ayse@isiltikuafor.test', '+905321110004'));
        $zeynep = $b->employee('Zeynep', [$kadikoy, $besiktas], [$cut, $blow, $mani]);
        $burak = $b->employee('Burak', [$besiktas], [$cut, $color]);
        $t1 = $b->get();
        app(SettingsRepository::class)->setBranch($kadikoy, ['whatsapp_manager_numbers' => ['+905321110002']]);
        WhatsAppConnection::withoutGlobalScopes()->create([
            'tenant_id' => $t1->id, 'branch_id' => $kadikoy->id, 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
            'display_phone' => '+902161112233', 'encrypted_credential_reference' => 'sandbox-token-not-real', 'status' => 'connected',
            'connected_at' => now(), 'webhook_subscribed' => true,
        ]);

        // 2) Profesyonel, automatic approval, Ankara
        $owner2 = $this->user('Mert Aksoy', 'sahip@modaberber.test', '+905321110010');
        $b2 = SalonBuilder::make()->tenant('Moda Berber', Plan::with('features')->where('slug', 'profesyonel')->first(), $owner2, ['description' => 'Klasik ve modern erkek kuaförlüğü.']);
        $b2->settings(['approval_mode' => 'automatic', 'slot_interval_minutes' => 15]);
        $cankaya = $b2->branch('Çankaya', ['city_id' => $ankara->id, 'district_id' => $d($ankara, 'cankaya'), 'address' => 'Tunalı Hilmi Cad. No:80']);
        $mcut = $b2->service('Erkek Saç Kesimi', 30, '400.00', [$cankaya], 5, 'sac-kesim');
        $beard = $b2->service('Sakal Tıraşı', 20, '250.00', [$cankaya], 5, 'sakal');
        $b2->employee('Ali', [$cankaya], [$mcut, $beard]);
        $b2->employee('Emre', [$cankaya], [$mcut, $beard]);
        $t2 = $b2->get();

        // 3) Standart, İzmir, single branch, not listed publicly
        $owner3 = $this->user('Deniz Arslan', 'sahip@denizguzellik.test', '+905321110020');
        $b3 = SalonBuilder::make()->tenant('Deniz Güzellik', Plan::with('features')->where('slug', 'standart')->first(), $owner3, ['public_listing_enabled' => false]);
        $alsancak = $b3->branch('Alsancak', ['city_id' => $izmir->id, 'district_id' => $d($izmir, 'konak'), 'address' => 'Kıbrıs Şehitleri Cad. No:20']);
        $skin = $b3->service('Cilt Bakımı', 60, '900.00', [$alsancak], 10, 'cilt-bakimi');
        $b3->employee('Deniz', [$alsancak], [$skin]);
        $t3 = $b3->get();

        // Customers and appointment history for tenant 1
        $customer = $this->user('Zehra Öztürk', 'musteri@example.test', '+905551234567');
        $svc = app(AppointmentService::class);
        $past = CarbonImmutable::now('Europe/Istanbul')->subDays(3)->setTime(11, 0);
        $this->travel($past->subDay(), function () use ($svc, $t1, $kadikoy, $cut, $color, $ayse, $zeynep, $past, $customer) {
            foreach ([[$cut, $ayse, '11:00', 'Zehra Öztürk', '+905551234567', 5, 'Harika bir kesim, çok memnun kaldım!'], [$color, $ayse, '13:00', 'Gizem Şahin', '+905551234568', 4, 'Renk tam istediğim gibi oldu.'], [$cut, $zeynep, '15:00', 'Büşra Can', '+905551234569', 5, null]] as [$service, $emp, $time, $name, $phone, $rating, $body]) {
                $a = $svc->create($t1, new CreateAppointmentData($kadikoy->id, [$service->id], $emp->id, CarbonImmutable::parse($past->toDateString().' '.$time, 'Europe/Istanbul')->utc(), $name, $phone, source: AppointmentSource::Web, byStaff: true, staffUserId: $t1->owner_user_id, userId: $phone === $customer->phone_e164 ? $customer->id : null))['appointment'];
                $this->pending[] = [$a, $rating, $body];
            }
        });
        $this->travel($past->addHours(6), function () use ($svc) {
            foreach ($this->pending as [$a, $rating, $body]) {
                $a = $svc->complete($a, Actor::system('seed'), [['amount' => $a->total_price->toDecimal(), 'method' => 'card']]);
                app(ReviewService::class)->create($a, $rating, $rating, $body);
            }
        });
        DB::table('tenant_customers')->where('phone_e164', $customer->phone_e164)->update(['user_id' => $customer->id]);

        // Upcoming: one confirmed, one pending (awaiting salon), one with an open proposal
        $tomorrow = CarbonImmutable::now('Europe/Istanbul')->addDays(2)->toDateString();
        $at = fn ($t) => CarbonImmutable::parse("$tomorrow $t", 'Europe/Istanbul')->utc();
        $svc->create($t1, new CreateAppointmentData($kadikoy->id, [$cut->id, $blow->id], $ayse->id, $at('10:00'), 'Zehra Öztürk', '+905551234567', 'musteri@example.test', userId: $customer->id, byStaff: true, staffUserId: $t1->owner_user_id));
        $pending = $svc->create($t1, new CreateAppointmentData($kadikoy->id, [$mani->id], null, $at('14:00'), 'Selin Aydın', '+905551234570', 'selin@example.test'))['appointment'];
        $toPropose = $svc->create($t1, new CreateAppointmentData($kadikoy->id, [$color->id], $ayse->id, $at('16:00'), 'Hande Er', '+905551234571', 'hande@example.test'))['appointment'];
        $svc->propose($toPropose, $at('17:00'), $ayse->id, Actor::system('seed'), 'Ayşe Hanım 17:00\'de müsait.');
        foreach (TenantCustomer::withoutGlobalScopes()->where('tenant_id', $t1->id)->get() as $c) {
            app(ConsentService::class)->grant($c, NotificationCategory::Transactional, NotificationChannel::WhatsApp, 'seed');
        }

        // Tenant 2: a few confirmed upcoming bookings
        foreach (['10:00', '10:30', '11:00'] as $i => $time) {
            $svc->create($t2, new CreateAppointmentData($cankaya->id, [$mcut->id], null, CarbonImmutable::parse("$tomorrow $time", 'Europe/Istanbul')->utc(), 'Müşteri '.($i + 1), '+90555200000'.$i));
        }

        $this->command?->info('Demo verisi oluşturuldu. Kullanıcılar ve şifre için README.md\'ye bakın.');
        unset($pending, $t3, $burak);
    }

    private array $pending = [];

    private function user(string $name, string $email, string $phone): User
    {
        $u = User::where('email', $email)->first() ?? new User;
        $u->forceFill(['name' => $name, 'email' => $email, 'phone_e164' => $phone, 'password' => self::PASSWORD,
            'email_verified_at' => now(), 'phone_verified_at' => now(), 'locale' => 'tr', 'status' => 'active'])->save();
        $u->inTeam((int) config('tenancy.platform_team_id'), fn (User $x) => $x->syncRoles(['customer']));

        return $u;
    }

    private function travel(CarbonImmutable $to, \Closure $fn): void
    {
        CarbonImmutable::setTestNow($to);
        Carbon::setTestNow($to);
        try {
            $fn();
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }
}
