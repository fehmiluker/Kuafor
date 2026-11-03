<?php

use App\Services\WhatsApp\TemplateCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phone verification moved from SMS to WhatsApp: add the AUTHENTICATION template row
 * (PENDING in meta mode until Meta approves it; existing templates are left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('whatsapp_templates')->whereNull('tenant_id')->where('key', 'otp_code')->where('language', 'tr')->exists()) {
            return;
        }
        $t = TemplateCatalog::get('otp_code');
        DB::table('whatsapp_templates')->insert([
            'tenant_id' => null, 'key' => 'otp_code', 'language' => 'tr', 'name' => $t['name'], 'category' => 'AUTHENTICATION',
            'approval_status' => config('whatsapp.mode') === 'meta' ? 'PENDING' : 'APPROVED',
            'parameters_schema' => json_encode(['body_params' => 1, 'quick_replies' => []]),
            'fallback_body' => $t['body'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_templates')->whereNull('tenant_id')->where('key', 'otp_code')->delete();
    }
};
