<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\BursaShowcaseSeeder;
use Illuminate\Console\Command;

/**
 * Fictional "örnek işletme" salons (Bursa) so the marketplace isn't empty at
 * launch. They are listed but never bookable. Remove when real salons join.
 */
class ShowcaseSalons extends Command
{
    protected $signature = 'kuafor:ornek-isletmeler {--kaldir : Örnek işletmeleri listeden kaldır}';

    protected $description = "Bursa'daki kurgusal örnek işletmeleri ekler veya kaldırır (randevu alınamaz)";

    public function handle(): int
    {
        if ($this->option('kaldir')) {
            $count = 0;
            Tenant::where('is_showcase', true)->each(function (Tenant $t) use (&$count) {
                $t->forceFill(['public_listing_enabled' => false])->save();
                $t->delete(); // soft delete: hidden everywhere, data kept
                $count++;
            });
            $this->info("{$count} örnek işletme kaldırıldı.");

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => BursaShowcaseSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
