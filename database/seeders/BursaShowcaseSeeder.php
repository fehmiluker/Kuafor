<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\City;
use App\Models\District;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Support\SalonBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fictional showcase ("örnek işletme") salons in Bursa so the marketplace is
 * not empty before real salons join. Names and street addresses are invented;
 * no phone numbers; booking is disabled for showcase salons (Tenant::bookable).
 * Idempotent: existing showcase salons (by slug) are skipped.
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
            ['Gece Makyajı', 60, '1500.00', 'makyaj'], ['Ağda (Tüm Bacak)', 40, '600.00', 'epilasyon'],
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
            ['Günlük Makyaj', 45, '900.00', 'makyaj'], ['Fön', 30, '250.00', 'fon-sekillendirme'],
        ]],
    ];

    public function run(): void
    {
        $city = City::where('slug', 'bursa')->first();
        $plan = Plan::with('features')->where('slug', 'profesyonel')->first() ?? Plan::with('features')->where('active', true)->orderBy('sort_order')->first();
        if (! $city || ! $plan) {
            $this->command?->warn('Bursa veya paket bulunamadı; önce temel veriyi yükleyin (php artisan db:seed).');

            return;
        }

        $created = 0;
        foreach (self::SALONS as [$name, $districtSlug, $address, $description, $image, $staff, $services]) {
            $slug = Str::slug($name).'-bursa';
            if (Tenant::withTrashed()->where('slug', $slug)->exists()) {
                continue;
            }
            $district = District::where('city_id', $city->id)->where('slug', $districtSlug)->first();
            // Placeholder owner; random password, unusable mailbox (.invalid).
            $owner = User::create(['name' => $name.' (örnek)', 'email' => 'ornek+'.$slug.'@kuaforrandevu.invalid', 'password' => Hash::make(Str::random(40))]);

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

        $this->command?->info("Bursa örnek işletmeleri: {$created} eklendi.");
    }
}
