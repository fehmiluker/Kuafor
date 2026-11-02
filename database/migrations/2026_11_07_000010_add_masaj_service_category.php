<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** New platform category "Masaj" (home page tile). Data migration so existing installs get it on `migrate`. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('service_categories')->whereNull('tenant_id')->where('slug', 'masaj')->exists()) {
            return;
        }
        DB::table('service_categories')->insert([
            'tenant_id' => null, 'slug' => 'masaj', 'name' => 'Masaj', 'status' => 'active',
            'sort_order' => (int) DB::table('service_categories')->whereNull('tenant_id')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Services may reference it; keep the row.
    }
};
