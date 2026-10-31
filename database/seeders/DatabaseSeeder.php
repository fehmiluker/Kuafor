<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Reference data is safe for every environment; demo data only outside production. */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, PlanSeeder::class, GeoSeeder::class, CategorySeeder::class, WhatsAppTemplateSeeder::class]);
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
