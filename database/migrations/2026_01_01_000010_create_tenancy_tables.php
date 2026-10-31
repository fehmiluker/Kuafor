<?php

use App\Enums\RecordStatus;
use App\Enums\TenantStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->unsignedSmallInteger('plate_code')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->timestamps();
            $table->unique(['city_id', 'slug']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 120)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->text('tax_info')->nullable()->comment('encrypted cast');
            $table->string('status', 20)->default(TenantStatus::Active->value);
            $table->boolean('public_listing_enabled')->default(true);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('timezone', 64)->default('Europe/Istanbul');
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->json('settings')->nullable()->comment('branding/theme only; booking rules live in tenant_settings');
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->decimal('min_service_price', 12, 2)->nullable()->comment('denormalized for search');
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'public_listing_enabled']);
        });
        TenantSchema::checkIn('tenants', 'status', TenantStatus::values());
        TenantSchema::check('tenants', 'chk_tenants_rating', '`rating_avg` IS NULL OR (`rating_avg` >= 1 AND `rating_avg` <= 5)');

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
        TenantSchema::checkIn('tenant_user', 'status', RecordStatus::values());

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 120);
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 500)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('timezone', 64)->default('Europe/Istanbul');
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['city_id', 'district_id', 'status']);
        });
        TenantSchema::checkIn('branches', 'status', RecordStatus::values());
        TenantSchema::check('branches', 'chk_branches_lat', '`lat` IS NULL OR (`lat` BETWEEN -90 AND 90)');
        TenantSchema::check('branches', 'chk_branches_lng', '`lng` IS NULL OR (`lng` BETWEEN -180 AND 180)');

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context', 30)->default('staff')->comment('role context in this branch');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            $table->unique(['branch_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('tenant_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->string('alt_text', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('visibility', 20)->default('public');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index(['tenant_id', 'sort_order']);
        });
        TenantSchema::checkIn('tenant_media', 'visibility', ['public', 'private']);
        TenantSchema::checkIn('tenant_media', 'mime', ['image/jpeg', 'image/png', 'image/webp']);
    }

    public function down(): void
    {
        foreach (['tenant_media', 'branch_user', 'branches', 'tenant_user', 'tenants', 'districts', 'cities'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
