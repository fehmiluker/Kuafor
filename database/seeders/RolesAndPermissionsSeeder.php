<?php

namespace Database\Seeders;

use App\Services\Tenancy\PermissionSynchronizer;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionSynchronizer::class)->sync();
    }
}
