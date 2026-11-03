<?php

namespace Database\Seeders;

use App\Http\Controllers\Web\Public\PublicController;
use App\Models\HomeContent;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/** Platform-wide default categories (tenant_id NULL) used for search filters. */
class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'sac-kesim' => 'Saç Kesimi', 'sac-boya' => 'Saç Boyama', 'fon-sekillendirme' => 'Fön & Şekillendirme',
        'sakal' => 'Sakal & Tıraş', 'manikur-pedikur' => 'Manikür & Pedikür', 'cilt-bakimi' => 'Cilt Bakımı',
        'makyaj' => 'Makyaj', 'kas-kirpik' => 'Kaş & Kirpik', 'epilasyon' => 'Epilasyon & Ağda', 'gelin' => 'Gelin Paketleri',
        'masaj' => 'Masaj',
    ];

    public function run(): void
    {
        $i = 0;
        foreach (self::CATEGORIES as $slug => $name) {
            $tile = collect(PublicController::HOME_CATEGORIES)->firstWhere('slug', $slug);
            $home = $tile ? ['home_label' => $tile['label'], 'icon' => $tile['icon'], 'tone' => $tile['tone'], 'show_on_home' => true] : [];
            ServiceCategory::query()->whereNull('tenant_id')->where('slug', $slug)->firstOr(fn () => (new ServiceCategory)->forceFill([
                'tenant_id' => null, 'slug' => $slug, 'name' => $name, 'status' => 'active', 'sort_order' => $i,
                ...$home,
            ])->save());
            HomeContent::where('kind', 'popular')->where('target_url', '/salonlar?kategori='.$slug)->whereNull('category_id')->update(['category_id' => ServiceCategory::whereNull('tenant_id')->where('slug', $slug)->value('id')]);
            $i++;
        }
    }
}
