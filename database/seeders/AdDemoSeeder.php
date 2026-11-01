<?php

namespace Database\Seeders;

use App\Models\AdCampaign;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample sponsor banner (fictitious salon-supply company, links to example.com)
 * so the ad slots are visible in the demo. Idempotent.
 */
class AdDemoSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('public');
        $images = [];
        foreach (['masaustu', 'mobil'] as $variant) {
            $target = "ads/demo-kuafor-malzeme-{$variant}.png";
            $disk->put($target, (string) file_get_contents(database_path("seeders/assets/ads/kuafor-malzeme-{$variant}.png")));
            $images[$variant] = $target;
        }

        foreach (['panel_dashboard' => 3, 'home' => 1] as $placement => $weight) {
            AdCampaign::updateOrCreate(
                ['advertiser' => 'Kuaför Malzeme Deposu (örnek)', 'placement' => $placement],
                [
                    'title' => 'Kuaför Malzeme Deposu: makas, fön, boya ve bakım ürünlerinde salonlara %15 indirim',
                    'image_path' => $images['masaustu'], 'image_mobile_path' => $images['mobil'],
                    'target_url' => 'https://example.com/kuafor-malzemeleri', 'weight' => $weight, 'active' => true,
                    'starts_at' => now()->subDay(), 'ends_at' => now()->addMonths(3),
                ],
            );
        }
    }
}
