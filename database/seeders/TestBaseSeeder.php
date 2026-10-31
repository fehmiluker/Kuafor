<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Minimal reference data for the automated test-suite. */
class TestBaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, PlanSeeder::class, CategorySeeder::class, WhatsAppTemplateSeeder::class]);
    }
}
