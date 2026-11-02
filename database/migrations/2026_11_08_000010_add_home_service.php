<?php

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Adrese hizmet": a branch can send staff to the customer's home/workplace in
 * selected districts (each with its own travel fee). Travel time blocks the
 * employee before and after the visit; the address is stored encrypted and
 * purged some time after the appointment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('home_service_enabled')->default(false);
            $table->unsignedSmallInteger('home_travel_minutes')->default(30)->comment('blocked before AND after each home visit');
            $table->decimal('home_min_total', 12, 2)->nullable()->comment('minimum services total for a home visit');
            $table->boolean('home_requires_approval')->default(true)->comment('home visits always wait for salon approval');
        });
        TenantSchema::check('branches', 'chk_branches_home_travel', '`home_travel_minutes` <= 240');

        Schema::create('branch_service_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('district_id')->constrained();
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches');
            $table->unique(['branch_id', 'district_id']);
            $table->index('district_id');
        });
        TenantSchema::check('branch_service_areas', 'chk_service_areas_fee', '`travel_fee` >= 0');

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('home_available')->default(false);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('home_service')->default(false);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('location_type', 10)->default('salon');
            $table->unsignedSmallInteger('travel_minutes')->default(0)->comment('blocked before starts_at; also included in blocked_until');
            $table->decimal('travel_fee', 12, 2)->default(0)->comment('included in total_price');
        });
        TenantSchema::checkIn('appointments', 'location_type', ['salon', 'address']);
        TenantSchema::check('appointments', 'chk_appointments_travel', '`travel_fee` >= 0');

        Schema::create('appointment_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->foreignId('district_id')->constrained();
            $table->string('place_type', 10)->default('home');
            $table->text('address_line')->nullable()->comment('encrypted; nulled by the purge job');
            $table->text('address_detail')->nullable()->comment('encrypted: floor/door/directions');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'appointment_id', 'appointments');
        });
        TenantSchema::checkIn('appointment_addresses', 'place_type', ['home', 'work']);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_addresses');
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['location_type', 'travel_minutes', 'travel_fee']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('home_service'));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('home_available'));
        Schema::dropIfExists('branch_service_areas');
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['home_service_enabled', 'home_travel_minutes', 'home_min_total', 'home_requires_approval']));
    }
};
