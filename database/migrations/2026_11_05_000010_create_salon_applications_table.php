<?php

use App\Enums\SalonApplicationStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Salonunu ekle" applications: a salon owner asks to be listed, a platform
 * admin approves (creates the salon + sends an invitation) or rejects.
 * Platform-level table (no tenant yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_applications', function (Blueprint $table) {
            $table->id();
            $table->string('business_name', 150);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->string('phone_e164', 20);
            $table->foreignId('city_id')->constrained();
            $table->string('district_name', 100)->nullable()->comment('free text; resolved to a district on approval');
            $table->string('address', 300);
            $table->json('categories')->nullable();
            $table->unsignedSmallInteger('employee_count')->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('status', 20)->default(SalonApplicationStatus::Pending->value);
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('kvkk_acknowledged_at');
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });
        TenantSchema::checkIn('salon_applications', 'status', SalonApplicationStatus::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_applications');
    }
};
