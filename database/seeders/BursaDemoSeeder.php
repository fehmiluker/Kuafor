<?php

namespace Database\Seeders;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentCompleted;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\ProposalCreated;
use App\Models\City;
use App\Models\District;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Reviews\ReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SalonBuilder;
use Database\Seeders\Support\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Demo salons in Bursa (fictional businesses, addresses and phone numbers) so the
 * public pages have content for the Bursa districts. Idempotent per salon (skipped
 * when its owner already owns a tenant), so it can also be run on an existing demo:
 *   php artisan db:seed --class=BursaDemoSeeder
 * NEVER run in production — demo users share the password "Demo12345!".
 */
class BursaDemoSeeder extends Seeder
{
    use SeedsDemoData;

    /**
     * services: [name, minutes, price, buffer, category slug]
     * employees: [name, service indexes]
     * reviews: [service index, employee index, local time, customer, rating, comment]
     */
    public const SALONS = [
        [
            'name' => 'Lavanta Kuaför', 'plan' => 'profesyonel', 'cover' => 'salon-1.jpg', 'approval' => 'automatic',
            'owner' => ['Seda Karaca', 'sahip@lavantakuafor.test', '+905321120001'],
            'description' => 'Nilüfer ve Mudanya\'da kadın kuaförü: kesim, renk, fön ve gelin saçı.',
            'branches' => [
                ['Nilüfer', 'nilufer', 'Fethiye Mah. FSM Bulvarı No:120', 40.2155, 28.9790, '+902242100001'],
                ['Mudanya', 'mudanya', 'Halitpaşa Mah. İskele Cad. No:14', 40.3755, 28.8830, '+902242100002'],
            ],
            'services' => [
                ['Kadın Saç Kesimi', 45, '600.00', 10, 'sac-kesim'], ['Fön', 30, '300.00', 5, 'fon-sekillendirme'],
                ['Dip Boya', 90, '1500.00', 15, 'sac-boya'], ['Balyaj', 180, '3500.00', 15, 'sac-boya'],
                ['Gelin Saçı', 120, '4000.00', 15, 'gelin'],
            ],
            'employees' => [['Gül', [0, 1, 2, 3, 4]], ['Pınar', [0, 1, 2]], ['Ece', [0, 1, 3]]],
            'reviews' => [
                [0, 0, '10:00', 'Merve Doğan', 5, 'Gül Hanım\'ın kesimi harika, kesinlikle tekrar geleceğim.'],
                [2, 1, '12:00', 'Aslı Tekin', 5, 'Boya rengi tam istediğim gibi oldu.'],
                [1, 2, '15:00', 'Cansu Er', 4, null],
            ],
        ],
        [
            'name' => 'Tophane Berber Evi', 'plan' => 'profesyonel', 'cover' => 'salon-2.jpg', 'approval' => 'automatic',
            'owner' => ['Hakan Yüce', 'sahip@tophaneberber.test', '+905321120002'],
            'description' => 'Osmangazi\'de klasik ustura tıraşı ve modern erkek kesimleri.',
            'branches' => [['Osmangazi', 'osmangazi', 'Tophane Mah. Kale Sok. No:8', 40.1875, 29.0560, '+902242100003']],
            'services' => [
                ['Erkek Saç Kesimi', 30, '350.00', 5, 'sac-kesim'], ['Sakal Tıraşı', 20, '200.00', 5, 'sakal'],
                ['Saç + Sakal', 45, '500.00', 5, 'sakal'], ['Çocuk Saç Kesimi', 20, '250.00', 5, 'sac-kesim'],
            ],
            'employees' => [['Kemal Usta', [0, 1, 2, 3]], ['Oğuz', [0, 1, 2]], ['Serkan', [0, 2, 3]]],
            'reviews' => [
                [2, 0, '11:00', 'Murat Kılıç', 5, 'Kemal Usta işinin ehli, sıcak havlu ile tıraş efsane.'],
                [0, 1, '14:00', 'Barış Koç', 4, 'Hızlı ve temiz, fiyatlar makul.'],
                [2, 2, '16:00', 'Onur Polat', 5, null],
            ],
        ],
        [
            'name' => 'Setbaşı Güzellik Merkezi', 'plan' => 'premium', 'cover' => 'salon-3.jpg', 'approval' => 'manual',
            'owner' => ['Derya Aydın', 'sahip@setbasiguzellik.test', '+905321120003'],
            'description' => 'Yıldırım\'da cilt bakımı, lazer epilasyon ve kaş-kirpik uygulamaları.',
            'branches' => [['Yıldırım', 'yildirim', 'Namazgah Mah. Setbaşı Cad. No:22', 40.1885, 29.0745, '+902242100004']],
            'services' => [
                ['Klasik Cilt Bakımı', 60, '900.00', 10, 'cilt-bakimi'], ['Hydrafacial', 75, '1600.00', 10, 'cilt-bakimi'],
                ['Lazer Epilasyon (Tüm Bacak)', 60, '1400.00', 10, 'epilasyon'], ['Ağda (Tüm Bacak)', 40, '500.00', 5, 'epilasyon'],
                ['Kaş Alımı', 20, '200.00', 5, 'kas-kirpik'],
            ],
            'employees' => [['Burcu', [0, 1, 4]], ['Sevgi', [2, 3]], ['Elif', [0, 3, 4]]],
            'reviews' => [
                [1, 0, '10:30', 'Tuğba Yalçın', 5, 'Cildim ışıl ışıl oldu, ortam çok temiz.'],
                [2, 1, '13:00', 'Nur Aksoy', 4, 'Randevu saatine tam uyuldu.'],
                [4, 2, '16:00', 'İrem Bozkurt', 5, 'Kaş şekli çok yakıştı.'],
            ],
        ],
        [
            'name' => 'Mavi Kıyı Nail Studio', 'plan' => 'standart', 'cover' => 'salon-1.jpg', 'approval' => 'automatic',
            'owner' => ['Ceren Uslu', 'sahip@mavikiyinail.test', '+905321120004'],
            'description' => 'Mudanya sahilinde manikür, pedikür, kalıcı oje ve protez tırnak.',
            'branches' => [['Mudanya', 'mudanya', 'Ömerbey Mah. Sahil Yolu No:31', 40.3770, 28.8790, '+902242100005']],
            'services' => [
                ['Manikür', 40, '400.00', 5, 'manikur-pedikur'], ['Pedikür', 50, '500.00', 5, 'manikur-pedikur'],
                ['Kalıcı Oje', 45, '550.00', 5, 'manikur-pedikur'], ['Protez Tırnak', 90, '1200.00', 10, 'manikur-pedikur'],
            ],
            'employees' => [['Ceren', [0, 1, 2, 3]], ['Damla', [0, 1, 2]]],
            'reviews' => [
                [2, 0, '11:00', 'Ezgi Şen', 5, 'Kalıcı oje üç haftadır ilk günkü gibi.'],
                [1, 1, '14:00', 'Sibel Kara', 4, null],
            ],
        ],
        [
            'name' => 'Görükle Kaş & Kirpik', 'plan' => 'standart', 'cover' => 'salon-3.jpg', 'approval' => 'automatic',
            'owner' => ['Melis Tan', 'sahip@goruklekirpik.test', '+905321120005'],
            'description' => 'Nilüfer Görükle\'de kirpik lifting, ipek kirpik, kaş laminasyonu ve günlük makyaj.',
            'branches' => [['Görükle', 'nilufer', 'Görükle Mah. Kampüs Cad. No:5', 40.2265, 28.8710, '+902242100006']],
            'services' => [
                ['Kirpik Lifting', 60, '750.00', 5, 'kas-kirpik'], ['İpek Kirpik', 90, '1100.00', 10, 'kas-kirpik'],
                ['Kaş Laminasyonu', 45, '650.00', 5, 'kas-kirpik'], ['Günlük Makyaj', 45, '800.00', 5, 'makyaj'],
            ],
            'employees' => [['Melis', [0, 1, 2, 3]], ['Buse', [0, 2, 3]]],
            'reviews' => [
                [0, 0, '12:00', 'Selin Acar', 5, 'Kirpiklerim çok doğal duruyor.'],
                [3, 1, '15:00', 'Dilara Kurt', 4, 'Makyaj gün boyu kaldı.'],
            ],
        ],
        [
            'name' => 'Usta Makas Erkek Kuaförü', 'plan' => 'standart', 'cover' => 'salon-2.jpg', 'approval' => 'automatic',
            'owner' => ['Yusuf Erdem', 'sahip@ustamakas.test', '+905321120006'],
            'description' => 'İnegöl merkezde erkek saç kesimi, sakal ve cilt bakımı.',
            'branches' => [['İnegöl', 'inegol', 'Cuma Mah. Kozluca Cad. No:44', 40.0785, 29.5105, '+902242100007']],
            'services' => [
                ['Erkek Saç Kesimi', 30, '300.00', 5, 'sac-kesim'], ['Sakal Tıraşı', 20, '180.00', 5, 'sakal'],
                ['Erkek Cilt Bakımı', 30, '400.00', 5, 'cilt-bakimi'],
            ],
            'employees' => [['Yusuf Usta', [0, 1, 2]], ['Ahmet', [0, 1]]],
            'reviews' => [
                [0, 0, '10:00', 'Furkan Aydemir', 5, 'İnegöl\'ün en iyi berberi.'],
                [1, 1, '13:00', 'Kerem Güneş', 4, null],
            ],
        ],
        [
            'name' => 'Zeytin Dalı Kuaför', 'plan' => 'profesyonel', 'cover' => 'salon-1.jpg', 'approval' => 'automatic',
            'owner' => ['Ayten Gür', 'sahip@zeytindali.test', '+905321120007'],
            'description' => 'Gemlik\'te kadın-erkek kuaförü; kesim, fön, boya ve keratin bakımı.',
            'branches' => [['Gemlik', 'gemlik', 'Hamidiye Mah. İstiklal Cad. No:17', 40.4315, 29.1560, '+902242100008']],
            'services' => [
                ['Kadın Saç Kesimi', 45, '500.00', 10, 'sac-kesim'], ['Erkek Saç Kesimi', 30, '300.00', 5, 'sac-kesim'],
                ['Fön', 30, '250.00', 5, 'fon-sekillendirme'], ['Keratin Bakımı', 120, '2200.00', 15, 'sac-boya'],
            ],
            'employees' => [['Ayten', [0, 2, 3]], ['Sinan', [1, 2]], ['Gamze', [0, 2, 3]]],
            'reviews' => [
                [3, 0, '10:00', 'Hülya Öz', 5, 'Keratinden sonra saçlarım ipek gibi.'],
                [1, 1, '14:00', 'Taner Yılmaz', 4, 'Güler yüzlü ekip.'],
            ],
        ],
        [
            'name' => 'Çekirge Gelin Evi', 'plan' => 'premium', 'cover' => 'salon-3.jpg', 'approval' => 'manual',
            'owner' => ['Nesrin Ateş', 'sahip@cekirgegelin.test', '+905321120008'],
            'description' => 'Osmangazi Çekirge\'de gelin saçı, gelin makyajı, nişan ve özel gün paketleri.',
            'branches' => [['Çekirge', 'osmangazi', 'Çekirge Mah. Çekirge Cad. No:60', 40.1950, 29.0330, '+902242100009']],
            'services' => [
                ['Gelin Paketi (Saç + Makyaj)', 180, '9000.00', 30, 'gelin'], ['Nişan Paketi', 120, '5000.00', 15, 'gelin'],
                ['Özel Gün Makyajı', 60, '1500.00', 10, 'makyaj'], ['Topuz', 60, '1200.00', 10, 'fon-sekillendirme'],
            ],
            'employees' => [['Nesrin', [0, 1, 2, 3]], ['Hilal', [1, 2, 3]], ['Esra', [2, 3]]],
            'reviews' => [
                [0, 0, '10:00', 'Gizem Arı', 5, 'Düğünümde kendimi prenses gibi hissettim, çok teşekkürler!'],
                [2, 1, '14:00', 'Yasemin Çelik', 5, 'Makyaj fotoğraflarda kusursuz çıktı.'],
            ],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('BursaDemoSeeder production ortamında çalıştırılamaz.');

            return;
        }
        Event::fake([AppointmentCreated::class, AppointmentConfirmed::class, AppointmentCompleted::class,
            ProposalCreated::class, AppointmentCancelled::class]);
        $bursa = City::where('slug', 'bursa')->firstOrFail();
        $created = 0;
        foreach (self::SALONS as $i => $salon) {
            $owner = User::where('email', $salon['owner'][1])->first();
            if ($owner && Tenant::where('owner_user_id', $owner->id)->exists()) {
                continue;
            }
            $this->salon($salon, $bursa, $i);
            $created++;
        }
        $this->command?->info("Bursa demo salonları: {$created} yeni salon oluşturuldu.");
    }

    private function salon(array $s, City $bursa, int $index): void
    {
        $plan = Plan::with('features')->where('slug', $s['plan'])->firstOrFail();
        $b = SalonBuilder::make()->tenant($s['name'], $plan, $this->user(...$s['owner']), ['description' => $s['description']]);
        $b->settings(['approval_mode' => $s['approval']]);
        $branches = [];
        foreach ($s['branches'] as [$name, $district, $address, $lat, $lng, $phone]) {
            $branches[] = $b->branch($name, [
                'city_id' => $bursa->id, 'district_id' => District::where('city_id', $bursa->id)->where('slug', $district)->value('id'),
                'address' => $address, 'lat' => $lat, 'lng' => $lng, 'phone' => $phone,
            ]);
        }
        $services = [];
        foreach ($s['services'] as [$name, $minutes, $price, $buffer, $category]) {
            $services[] = $b->service($name, $minutes, $price, $branches, $buffer, $category);
        }
        $employees = [];
        foreach ($s['employees'] as [$name, $serviceIdx]) {
            $employees[] = $b->employee($name, $branches, array_map(fn ($k) => $services[$k], $serviceIdx));
        }
        $tenant = $b->get();
        $branch = $branches[0];
        $svc = app(AppointmentService::class);

        // Completed past appointments with verified reviews (they drive the rating shown on salon cards).
        $past = CarbonImmutable::now('Europe/Istanbul')->subDays(4 + $index % 3)->setTime(9, 0);
        $done = [];
        $this->travel($past->subDay(), function () use ($s, $svc, $tenant, $branch, $services, $employees, $past, $index, &$done) {
            foreach ($s['reviews'] as $r => [$service, $employee, $time, $customer, $rating, $body]) {
                $phone = sprintf('+9055530%02d%03d', $index, $r);
                $a = $svc->create($tenant, new CreateAppointmentData($branch->id, [$services[$service]->id], $employees[$employee]->id,
                    CarbonImmutable::parse($past->toDateString().' '.$time, 'Europe/Istanbul')->utc(), $customer, $phone,
                    source: AppointmentSource::Web, byStaff: true, staffUserId: $tenant->owner_user_id))['appointment'];
                $done[] = [$a, $rating, $body];
            }
        });
        $this->travel($past->addHours(10), function () use ($svc, $done) {
            foreach ($done as [$a, $rating, $body]) {
                $a = $svc->complete($a, Actor::system('seed'), [['amount' => $a->total_price->toDecimal(), 'method' => 'cash']]);
                app(ReviewService::class)->create($a, $rating, $rating, $body);
            }
        });

        // A couple of upcoming bookings so the panel calendar is not empty.
        $day = CarbonImmutable::now('Europe/Istanbul')->addDays(2)->toDateString();
        foreach (['10:00', '14:00'] as $k => $time) {
            $svc->create($tenant, new CreateAppointmentData($branch->id, [$services[0]->id], null,
                CarbonImmutable::parse("$day $time", 'Europe/Istanbul')->utc(), 'Demo Müşteri '.($k + 1), sprintf('+9055531%02d%03d', $index, $k),
                byStaff: true, staffUserId: $tenant->owner_user_id));
        }

        $path = "tenants/{$tenant->id}/media/demo-cover.jpg";
        Storage::disk('public')->put($path, (string) file_get_contents(database_path('seeders/assets/demo/'.$s['cover'])));
        $tenant->forceFill(['cover_path' => $path])->save();
    }
}
