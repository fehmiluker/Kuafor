<?php

namespace Database\Seeders;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ApprovalMode;
use App\Enums\ReviewStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\District;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reviews\ReviewService;
use App\Support\Token;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fictional showcase ("örnek işletme") salons in Bursa so the marketplace is
 * not empty before real salons join. Names and street addresses are invented;
 * no phone numbers; booking is disabled for showcase salons (Tenant::bookable).
 * Each gets a few sample reviews, labelled "Örnek yorum" on the salon page and
 * left out of the schema.org rating. Idempotent: existing salons (by slug) are
 * skipped; reviews are only added to showcase salons that have none.
 * Remove later with: php artisan kuafor:ornek-isletmeler --kaldir
 */
class BursaShowcaseSeeder extends Seeder
{
    /** [name, district slug, address, description, image, staff, services: [name, minutes, price, category]] */
    public const SALONS = [
        ['Lavanta Saç Tasarım', 'nilufer', 'Fethiye Mah. Menekşe Sk. No:8/A', 'Kesim, renk ve bakımda modern dokunuşlar.', 'salon-1.jpg', ['Selin', 'Merve'], [
            ['Kadın Saç Kesimi', 45, '550.00', 'sac-kesim'], ['Fön', 30, '300.00', 'fon-sekillendirme'], ['Dip Boya', 90, '1400.00', 'sac-boya'],
            ['Balyaj', 180, '3200.00', 'sac-boya'], ['Keratin Bakım', 120, '2500.00', 'cilt-bakimi'],
        ]],
        ['Yaprak Güzellik Stüdyosu', 'nilufer', 'Özlüce Mah. Papatya Cad. No:21', 'Cilt bakımı, kaş-kirpik ve makyaj hizmetleri.', 'salon-2.jpg', ['Ece', 'Dilara'], [
            ['Klasik Cilt Bakımı', 60, '1100.00', 'cilt-bakimi'], ['Kaş Tasarımı', 20, '200.00', 'kas-kirpik'], ['Kirpik Lifting', 45, '700.00', 'kas-kirpik'],
            ['Gece Makyajı', 60, '1500.00', 'makyaj'], ['Ağda (Tüm Bacak)', 40, '600.00', 'epilasyon'], ['Klasik Masaj', 50, '1200.00', 'masaj'],
        ]],
        ['Usta Makas Erkek Kuaförü', 'osmangazi', 'Çekirge Mah. Çınar Sk. No:3', 'Klasik ve modern erkek kesimleri, sakal bakımı.', 'salon-3.jpg', ['Mehmet Usta', 'Can'], [
            ['Erkek Saç Kesimi', 30, '350.00', 'sac-kesim'], ['Sakal Tıraşı', 20, '200.00', 'sakal'], ['Saç + Sakal', 45, '500.00', 'sakal'],
            ['Çocuk Saç Kesimi', 25, '250.00', 'sac-kesim'], ['Yüz Maskesi', 20, '250.00', 'cilt-bakimi'],
        ]],
        ['Tarz Berber Atölyesi', 'yildirim', 'Mimarsinan Mah. Lale Sk. No:17', 'Fade kesimler ve sıcak havlulu tıraş.', 'salon-4.jpg', ['Burak', 'Emre'], [
            ['Fade Kesim', 40, '400.00', 'sac-kesim'], ['Sıcak Havlu Tıraş', 30, '250.00', 'sakal'], ['Sakal Şekillendirme', 20, '200.00', 'sakal'],
            ['Saç Boyama (Erkek)', 45, '600.00', 'sac-boya'],
        ]],
        ['İnci Kadın Kuaförü', 'osmangazi', 'Kükürtlü Mah. Gül Sk. No:5', 'Gelin ve özel gün saçlarında 20 yıllık deneyim.', 'salon-5.jpg', ['Nur', 'Aslı', 'Zehra'], [
            ['Kadın Saç Kesimi', 45, '500.00', 'sac-kesim'], ['Topuz', 60, '900.00', 'fon-sekillendirme'], ['Gelin Saçı', 120, '3500.00', 'gelin'],
            ['Komple Boya', 120, '1800.00', 'sac-boya'], ['Fön', 30, '250.00', 'fon-sekillendirme'],
        ]],
        ['Nar Çiçeği Tırnak & Bakım', 'nilufer', 'Beşevler Mah. Nar Sk. No:12', 'Manikür, pedikür ve kalıcı oje.', 'salon-6.jpg', ['Gizem', 'Buse'], [
            ['Manikür', 40, '400.00', 'manikur-pedikur'], ['Pedikür', 50, '500.00', 'manikur-pedikur'], ['Kalıcı Oje', 45, '500.00', 'manikur-pedikur'],
            ['Protez Tırnak', 90, '1200.00', 'manikur-pedikur'],
        ]],
        ['Mavi Ayna Kuaför', 'mudanya', 'Güzelyalı Mah. Deniz Cad. No:44', 'Deniz kenarında kadın-erkek kuaför.', 'salon-7.jpg', ['Elif', 'Kerem'], [
            ['Kadın Saç Kesimi', 45, '500.00', 'sac-kesim'], ['Erkek Saç Kesimi', 30, '300.00', 'sac-kesim'], ['Fön', 30, '280.00', 'fon-sekillendirme'],
            ['Ombre', 150, '2800.00', 'sac-boya'], ['Manikür', 40, '350.00', 'manikur-pedikur'],
        ]],
        ['Zeytin Dalı Güzellik', 'gemlik', 'Hamidiye Mah. Zeytin Sk. No:9', 'Bakım, epilasyon ve makyaj.', 'salon-8.jpg', ['Seda', 'İrem'], [
            ['Cilt Bakımı', 60, '950.00', 'cilt-bakimi'], ['Lazer Epilasyon (Bölge)', 30, '800.00', 'epilasyon'], ['Kaş Alımı', 15, '150.00', 'kas-kirpik'],
            ['Günlük Makyaj', 45, '900.00', 'makyaj'], ['Fön', 30, '250.00', 'fon-sekillendirme'], ['Sırt-Boyun Masajı', 30, '700.00', 'masaj'],
        ]],
        ['Kestane Saç Stüdyosu', 'inegol', 'Mahmudiye Mah. Kestane Sk. No:14', 'İnegöl merkezde kadın kuaförü; kesim, renk ve bakım.', 'salon-2.jpg', ['Hande', 'Özge'], [
            ['Kadın Saç Kesimi', 45, '450.00', 'sac-kesim'], ['Fön', 30, '250.00', 'fon-sekillendirme'], ['Dip Boya', 90, '1200.00', 'sac-boya'],
            ['Röfle', 120, '1900.00', 'sac-boya'], ['Maşa', 40, '350.00', 'fon-sekillendirme'],
        ]],
        ['Sultan Makas Erkek Bakım', 'inegol', 'Cuma Mah. Selvi Sk. No:6', 'Erkek kesimi, sakal tasarımı ve cilt bakımı.', 'salon-3.jpg', ['Yusuf', 'Ahmet'], [
            ['Erkek Saç Kesimi', 30, '300.00', 'sac-kesim'], ['Sakal Tıraşı', 20, '180.00', 'sakal'], ['Saç + Sakal', 45, '430.00', 'sakal'],
            ['Erkek Cilt Bakımı', 30, '400.00', 'cilt-bakimi'],
        ]],
        ['İpek Yolu Güzellik Merkezi', 'osmangazi', 'Altıparmak Mah. Koza Sk. No:11', 'Cilt bakımı, lazer epilasyon ve kaş-kirpik uygulamaları.', 'salon-6.jpg', ['Burcu', 'Sevgi', 'Tuba'], [
            ['Hydrafacial', 75, '1700.00', 'cilt-bakimi'], ['Klasik Cilt Bakımı', 60, '1000.00', 'cilt-bakimi'], ['Lazer Epilasyon (Tüm Bacak)', 60, '1500.00', 'epilasyon'],
            ['Ağda (Tüm Bacak)', 40, '550.00', 'epilasyon'], ['Kaş Laminasyonu', 45, '650.00', 'kas-kirpik'], ['Aromaterapi Masajı', 60, '1500.00', 'masaj'],
        ]],
        ['Badem Çiçeği Kuaför', 'gursu', 'Kurtuluş Mah. Badem Sk. No:7', 'Gürsu\'da kadın-erkek kuaför, fön ve boya.', 'salon-7.jpg', ['Aylin', 'Serkan'], [
            ['Kadın Saç Kesimi', 45, '420.00', 'sac-kesim'], ['Erkek Saç Kesimi', 30, '250.00', 'sac-kesim'], ['Fön', 30, '220.00', 'fon-sekillendirme'],
            ['Dip Boya', 90, '1100.00', 'sac-boya'],
        ]],
        ['Beyaz Tül Gelin Evi', 'kestel', 'Ahmet Vefik Paşa Mah. Gelincik Sk. No:2', 'Gelin, nişan ve kına paketleri; prova dahil.', 'salon-5.jpg', ['Nesrin', 'Hilal', 'Esra'], [
            ['Gelin Paketi (Saç + Makyaj)', 180, '8000.00', 'gelin'], ['Nişan Paketi', 120, '4500.00', 'gelin'], ['Özel Gün Makyajı', 60, '1400.00', 'makyaj'],
            ['Topuz', 60, '1000.00', 'fon-sekillendirme'], ['Kına Gecesi Saçı', 90, '1800.00', 'gelin'],
        ]],
        ['Kirpik Atölyesi', 'nilufer', 'İhsaniye Mah. Akasya Sk. No:19', 'İpek kirpik, kirpik lifting ve kaş tasarımı.', 'salon-2.jpg', ['Melis', 'Cansu'], [
            ['İpek Kirpik', 90, '1100.00', 'kas-kirpik'], ['Kirpik Lifting', 60, '750.00', 'kas-kirpik'], ['Kaş Laminasyonu', 45, '650.00', 'kas-kirpik'],
            ['Kaş Alımı', 15, '180.00', 'kas-kirpik'], ['Günlük Makyaj', 45, '850.00', 'makyaj'],
        ]],
        ['Liman Berber', 'gemlik', 'Osmaniye Mah. Liman Cad. No:28', 'Gemlik sahilinde klasik berber; ustura ve sıcak havlu.', 'salon-3.jpg', ['Kemal Usta', 'Oğuz'], [
            ['Erkek Saç Kesimi', 30, '300.00', 'sac-kesim'], ['Ustura Tıraş', 30, '220.00', 'sakal'], ['Saç + Sakal', 45, '450.00', 'sakal'],
        ]],
        ['Şeftali Güzellik', 'mustafakemalpasa', 'Şeyhmüftü Mah. Şeftali Sk. No:3', 'Manikür, pedikür, ağda ve cilt bakımı.', 'salon-6.jpg', ['Derya', 'Fatma'], [
            ['Manikür', 40, '300.00', 'manikur-pedikur'], ['Pedikür', 50, '380.00', 'manikur-pedikur'], ['Ağda (Tüm Bacak)', 40, '400.00', 'epilasyon'],
            ['Cilt Bakımı', 60, '750.00', 'cilt-bakimi'], ['Klasik Masaj', 50, '900.00', 'masaj'],
        ]],
        ['Çamlık Saç Evi', 'karacabey', 'Runguşpaşa Mah. Çam Sk. No:10', 'Karacabey\'de aile kuaförü; kadın, erkek ve çocuk.', 'salon-1.jpg', ['Songül', 'Murat'], [
            ['Kadın Saç Kesimi', 45, '400.00', 'sac-kesim'], ['Erkek Saç Kesimi', 30, '250.00', 'sac-kesim'], ['Çocuk Saç Kesimi', 20, '180.00', 'sac-kesim'],
            ['Fön', 30, '200.00', 'fon-sekillendirme'], ['Komple Boya', 120, '1400.00', 'sac-boya'],
        ]],
        ['Göl Kıyısı Kuaför', 'orhangazi', 'Yeni Mah. Göl Cad. No:15', 'İznik Gölü yolu üzerinde kadın kuaförü.', 'salon-7.jpg', ['Gülşen', 'Neslihan'], [
            ['Kadın Saç Kesimi', 45, '420.00', 'sac-kesim'], ['Fön', 30, '220.00', 'fon-sekillendirme'], ['Ombre', 150, '2400.00', 'sac-boya'],
            ['Manikür', 40, '300.00', 'manikur-pedikur'],
        ]],
        ['Mor Salkım Nail Bar', 'osmangazi', 'Hamitler Mah. Salkım Sk. No:9', 'Kalıcı oje, protez tırnak ve nail art.', 'salon-6.jpg', ['Ceren', 'Damla'], [
            ['Kalıcı Oje', 45, '480.00', 'manikur-pedikur'], ['Protez Tırnak', 90, '1150.00', 'manikur-pedikur'], ['Nail Art (10 tırnak)', 30, '350.00', 'manikur-pedikur'],
            ['Pedikür', 50, '450.00', 'manikur-pedikur'],
        ]],
        ['Papatya Kadın Kuaförü', 'yildirim', 'Yiğitler Mah. Papatya Sk. No:23', 'Kesim, fön, topuz ve boya; uygun fiyatlı mahalle kuaförü.', 'salon-5.jpg', ['Emine', 'Kübra'], [
            ['Kadın Saç Kesimi', 45, '380.00', 'sac-kesim'], ['Fön', 30, '200.00', 'fon-sekillendirme'], ['Topuz', 60, '700.00', 'fon-sekillendirme'],
            ['Dip Boya', 90, '1000.00', 'sac-boya'],
        ]],
    ];

    /** Sample review texts per platform category ("örnek yorum"; shown with a label, never as verified). */
    private const REVIEW_TEXTS = [
        'sac-kesim' => ['Kesim tam istediğim gibi oldu, çok memnun kaldım.', 'Saçımı dinleyip ona göre kestiler, teşekkürler.', 'Hızlı ve özenli bir kesim.', 'Yıllardır gittiğim en iyi kesim diyebilirim.'],
        'sac-boya' => ['Renk tam istediğim tonda çıktı.', 'Boyadan sonra saçım hiç yıpranmadı.', 'Balyaj çok doğal duruyor, herkes soruyor.', 'Renk konusunda gerçekten bilgililer.'],
        'fon-sekillendirme' => ['Fön akşama kadar bozulmadı.', 'Topuz düğün boyunca dağılmadı, harikaydı.', 'Pratik ve güzel bir fön.'],
        'sakal' => ['Sıcak havlu ile tıraş çok rahatlatıcıydı.', 'Sakal şekli tam yüzüme göre oldu.', 'Usta işinin ehli, temiz iş.', 'Hızlı ve titiz.'],
        'manikur-pedikur' => ['Kalıcı oje üç hafta sorunsuz gitti.', 'Çok hijyenik bir ortam, içim rahat etti.', 'Tırnaklarım hiç bu kadar güzel olmamıştı.'],
        'cilt-bakimi' => ['Cildim ışıl ışıl oldu.', 'Bakım sırasında her adımı anlattılar.', 'Ortam çok huzurlu, tekrar geleceğim.'],
        'masaj' => ['Masaj sonrası bütün yorgunluğum geçti.', 'Çok rahatlatıcı bir ortam, tekrar geleceğim.', 'Boyun ağrılarıma iyi geldi.'],
        'makyaj' => ['Makyaj fotoğraflarda kusursuz çıktı.', 'Doğal ve gün boyu kalıcı bir makyaj.', 'Tam istediğim gibi, abartısız.'],
        'kas-kirpik' => ['Kaş şekli yüzüme çok yakıştı.', 'Kirpiklerim çok doğal duruyor.', 'Lifting sonrası maskaraya gerek kalmadı.'],
        'epilasyon' => ['Neredeyse hiç acı hissetmedim.', 'Seanslar düzenli ve dakik.', 'Sonuçtan çok memnunum.'],
        'gelin' => ['Düğünümde kendimi çok güzel hissettim, çok teşekkürler!', 'Prova ile her şey kafamdaki gibi oldu.', 'Gelin saçı gece boyunca hiç bozulmadı.'],
    ];

    private const REVIEW_EXTRA = [null, null, 'Randevu saatine tam uyuldu.', 'Fiyatlar makul.', 'Güler yüzlü bir ekip.', 'Kesinlikle tavsiye ederim.'];

    private const CUSTOMER_NAMES = ['Merve Doğan', 'Aslı Tekin', 'Cansu Er', 'Murat Kılıç', 'Barış Koç', 'Tuğba Yalçın', 'Nur Aksoy', 'İrem Bozkurt',
        'Ezgi Şen', 'Sibel Kara', 'Selin Acar', 'Dilara Kurt', 'Furkan Aydemir', 'Kerem Güneş', 'Hülya Öz', 'Taner Yılmaz', 'Gizem Arı',
        'Yasemin Çelik', 'Ayşe Demir', 'Fatma Şahin', 'Emre Arslan', 'Burak Özdemir', 'Zeynep Aydın', 'Elif Kaya', 'Hakan Çetin', 'Seda Yıldız',
        'Gamze Polat', 'Özlem Korkmaz', 'Serkan Erdoğan', 'Pınar Kurt', 'Ebru Özkan', 'Volkan Aslan', 'Buse Tunç', 'Derya Uçar', 'Oya Bulut'];

    /** Stable set of 100 fictional salons; repeat runs never duplicate them. */
    public static function salons(): array
    {
        $salons = self::SALONS;
        $names = ['Amber', 'Lale', 'Mimoza', 'Safir', 'Mercan', 'Lavanta', 'Defne', 'Yasemin', 'Zümrüt', 'İnci', 'Begonya', 'Frezya', 'Orkide', 'Menekşe', 'Manolya', 'Fulya'];
        $types = ['Saç Atölyesi', 'Berber Stüdyo', 'Güzellik Atölyesi', 'Tırnak Stüdyo', 'Bakım ve Spa'];
        for ($i = 0; $i < 80; $i++) {
            $template = self::SALONS[$i % count(self::SALONS)];
            $template[0] = $names[intdiv($i, 5)].' '.$types[$i % 5];
            $template[2] = 'Tanıtım kaydı — ziyaret ve randevu kabul edilmez';
            $template[3] = 'Kurgusal tanıtım profili. Hizmet ve fiyatlar temsili olup bu profilden randevu alınamaz.';
            $salons[] = $template;
        }
        return $salons;
    }

    public function run(): void
    {
        $city = City::where('slug', 'bursa')->first();
        $plan = Plan::with('features')->where('slug', 'profesyonel')->first() ?? Plan::with('features')->where('active', true)->orderBy('sort_order')->first();
        if (! $city || ! $plan) {
            $this->command?->warn('Bursa veya paket bulunamadı; önce temel veriyi yükleyin (php artisan db:seed).');

            return;
        }

        // Districts used below (GeoSeeder list; idempotent, also fixes databases seeded before they were added).
        foreach (GeoSeeder::DISTRICTS[16] as $d) {
            $city->districts()->firstOrCreate(['slug' => Str::slug($d)], ['name' => $d]);
        }

        // Showcase salons dropped from the list (e.g. 22 -> 20) are removed like `--kaldir` does.
        $slugs = array_map(fn ($salon) => Str::slug($salon[0]).'-bursa', self::salons());
        $removed = 0;
        Tenant::where('is_showcase', true)->whereNotIn('slug', $slugs)->each(function (Tenant $t) use (&$removed) {
            $t->forceFill(['public_listing_enabled' => false])->save();
            $t->delete();
            $removed++;
        });

        $created = 0;
        foreach (self::salons() as [$name, $districtSlug, $address, $description, $image, $staff, $services]) {
            $slug = Str::slug($name).'-bursa';
            if ($existing = Tenant::withTrashed()->where('slug', $slug)->first()) {
                // Services added to the list later (e.g. "Masaj") are added to salons from earlier runs.
                if (! $existing->trashed()) {
                    $this->addMissingServices($existing, $services);
                }

                continue;
            }
            $district = District::where('city_id', $city->id)->where('slug', $districtSlug)->first();
            // Placeholder owner; random password, unusable mailbox (.invalid).
            $owner = User::firstOrCreate(['email' => 'ornek+'.$slug.'@kuaforrandevu.invalid'], ['name' => $name.' (örnek)', 'password' => Hash::make(Str::random(40))]);

            $b = SalonBuilder::make()->tenant($name, $plan, $owner, ['slug' => $slug, 'is_showcase' => true, 'description' => $description]);
            $branch = $b->branch('Merkez', ['address' => $address, 'city_id' => $city->id, 'district_id' => $district?->id, 'phone' => null],
                [1 => ['09:00', '19:00'], 2 => ['09:00', '19:00'], 3 => ['09:00', '19:00'], 4 => ['09:00', '19:00'], 5 => ['09:00', '20:00'], 6 => ['09:00', '20:00']]);
            $svc = array_map(fn ($s) => $b->service($s[0], $s[1], $s[2], [$branch], 0, $s[3]), $services);
            foreach ($staff as $person) {
                $b->employee($person, [$branch], $svc);
            }
            $tenant = $b->get();

            $path = "tenants/{$tenant->id}/media/showcase-cover.jpg";
            Storage::disk('public')->put($path, (string) file_get_contents(database_path('seeders/assets/showcase/'.$image)));
            $tenant->forceFill(['cover_path' => $path])->save();

            // Keep listed long-term (a trial would expire and hide it).
            Subscription::where('tenant_id', $tenant->id)->update([
                'status' => SubscriptionStatus::Active->value, 'trial_ends_at' => null,
                'current_period_start' => now(), 'current_period_end' => now()->addYears(5),
            ]);
            $created++;
        }

        // Sample reviews for every showcase salon that has none yet (also the ones created by earlier runs).
        $reviewed = 0;
        foreach (Tenant::where('is_showcase', true)->where('rating_count', 0)->get() as $tenant) {
            $this->sampleReviews($tenant);
            $reviewed++;
        }

        $this->command?->info("Bursa örnek işletmeleri: {$created} eklendi, {$reviewed} işletmeye örnek yorum eklendi".($removed ? ", listeden çıkan {$removed} örnek işletme kaldırıldı." : '.'));
    }

    /** @param list<array{0:string,1:int,2:string,3:string}> $services */
    private function addMissingServices(Tenant $tenant, array $services): void
    {
        $have = DB::table('services')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->pluck('name')->all();
        $missing = array_filter($services, fn ($s) => ! in_array($s[0], $have, true));
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();
        if ($missing === [] || ! $branch) {
            return;
        }
        $b = SalonBuilder::make()->forTenant($tenant);
        $employees = DB::table('employees')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->pluck('id');
        foreach ($missing as $s) {
            $service = $b->service($s[0], $s[1], $s[2], [$branch], 0, $s[3]);
            foreach ($employees as $employeeId) {
                DB::table('employee_services')->insert(['tenant_id' => $tenant->id, 'employee_id' => $employeeId, 'service_id' => $service->id,
                    'branch_id' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Completed appointments + published reviews written directly (no booking flow, no
     * events/notifications). Deterministic per salon. Customers get unallocated
     * +90 5000… numbers so no real person is ever linked to them.
     */
    private function sampleReviews(Tenant $tenant): void
    {
        mt_srand(crc32($tenant->slug));
        $branch = DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('id')->first();
        $services = DB::table('services')->leftJoin('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.tenant_id', $tenant->id)->whereNull('services.deleted_at')
            ->select('services.id', 'services.name', 'services.duration_minutes', 'services.buffer_minutes', 'services.base_price', 'service_categories.slug as category')
            ->orderBy('services.id')->get();
        $employees = DB::table('employees')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id');
        if (! $branch || $services->isEmpty() || $employees->isEmpty()) {
            return;
        }
        $names = self::CUSTOMER_NAMES;
        shuffle($names);
        $count = mt_rand(3, 7);
        for ($i = 0; $i < $count; $i++) {
            $service = $services[mt_rand(0, $services->count() - 1)];
            $texts = self::REVIEW_TEXTS[$service->category] ?? self::REVIEW_TEXTS['sac-kesim'];
            $roll = mt_rand(1, 100);
            $rating = $roll <= 65 ? 5 : ($roll <= 92 ? 4 : 3);
            $body = $rating === 3 ? 'İşlem güzeldi ama biraz bekledim.' : trim($texts[mt_rand(0, count($texts) - 1)].' '.(self::REVIEW_EXTRA[mt_rand(0, count(self::REVIEW_EXTRA) - 1)] ?? ''));
            $start = CarbonImmutable::now('Europe/Istanbul')->subDays(mt_rand(3, 120))->setTime(mt_rand(10, 16), mt_rand(0, 1) * 30)->utc();
            $end = $start->addMinutes($service->duration_minutes);
            $reviewedAt = $end->addHours(mt_rand(2, 30));
            $employeeId = $employees[mt_rand(0, $employees->count() - 1)];

            $phone = sprintf('+905000%06d', $tenant->id % 10000 * 100 + $i);
            $customerId = DB::table('tenant_customers')->where('tenant_id', $tenant->id)->where('phone_e164', $phone)->value('id')
                ?? DB::table('tenant_customers')->insertGetId([
                    'tenant_id' => $tenant->id, 'full_name' => $names[$i], 'phone_e164' => $phone,
                    'status' => 'active', 'last_visit_at' => $start, 'created_at' => $start, 'updated_at' => $start,
                ]);
            $appointmentId = DB::table('appointments')->insertGetId([
                'public_reference' => Token::reference(10), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'customer_id' => $customerId,
                'employee_id' => $employeeId, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'blocked_until_utc' => $end->addMinutes($service->buffer_minutes),
                'status' => AppointmentStatus::Completed->value, 'approval_mode_snapshot' => ApprovalMode::Automatic->value,
                'subtotal' => $service->base_price, 'total_price' => $service->base_price, 'currency' => 'TRY',
                'confirmed_at' => $start->subDay(), 'completed_at' => $end, 'source' => AppointmentSource::Web->value,
                'created_at' => $start->subDays(2), 'updated_at' => $end,
            ]);
            DB::table('appointment_services')->insert([
                'tenant_id' => $tenant->id, 'appointment_id' => $appointmentId, 'service_id' => $service->id, 'service_name_snapshot' => $service->name,
                'duration_minutes_snapshot' => $service->duration_minutes, 'buffer_minutes_snapshot' => $service->buffer_minutes,
                'unit_price_snapshot' => $service->base_price, 'sort_order' => 0, 'created_at' => $start, 'updated_at' => $start,
            ]);
            DB::table('reviews')->insert([
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'appointment_id' => $appointmentId, 'customer_id' => $customerId,
                'employee_id' => $employeeId, 'rating' => $rating, 'employee_rating' => $rating, 'body' => $body,
                'status' => ReviewStatus::Published->value, 'verified_at' => $reviewedAt, 'created_at' => $reviewedAt, 'updated_at' => $reviewedAt,
            ]);
        }
        mt_srand();
        app(ReviewService::class)->recalculate($tenant->id);
    }
}
