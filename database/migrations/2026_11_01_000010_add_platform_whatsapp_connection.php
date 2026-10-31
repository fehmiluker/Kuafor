<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid WhatsApp model:
 *  - one platform-wide connection (is_platform = 1, no tenant/branch) used as the
 *    default sender for every salon without its own number,
 *  - optional per-branch connections (Embedded Signup or admin-assisted).
 * Inbound messages on the platform number may arrive before their tenant is known,
 * so message ownership becomes nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            $table->boolean('is_platform')->default(false)->after('branch_id');
            $table->string('onboarding_method', 20)->default('manual')->after('is_platform')->comment('manual|embedded_signup|admin|platform');
            $table->text('encrypted_pin')->nullable()->after('encrypted_credential_reference')->comment('2-step verification PIN set at registration (encrypted)');
            $table->index('is_platform');
        });
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
            $table->unsignedBigInteger('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropIndex(['is_platform']);
            $table->dropColumn(['is_platform', 'onboarding_method', 'encrypted_pin']);
        });
    }
};
