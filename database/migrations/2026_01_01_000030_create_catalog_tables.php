<?php

use App\Enums\RecordStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->comment('NULL = platform default')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });
        TenantSchema::checkIn('service_categories', 'status', RecordStatus::values());

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->decimal('base_price', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'active']);
        });
        TenantSchema::check('services', 'chk_services_duration', '`duration_minutes` BETWEEN 5 AND 720');
        TenantSchema::check('services', 'chk_services_buffer', '`buffer_minutes` BETWEEN 0 AND 240');
        TenantSchema::check('services', 'chk_services_price', '`base_price` >= 0');

        Schema::create('branch_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('service_id');
            $table->decimal('price_override', 12, 2)->nullable();
            $table->unsignedSmallInteger('duration_override')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            TenantSchema::foreign($table, 'service_id', 'services', 'cascade');
            $table->unique(['branch_id', 'service_id']);
        });
        TenantSchema::check('branch_services', 'chk_branch_services_price', '`price_override` IS NULL OR `price_override` >= 0');
        TenantSchema::check('branch_services', 'chk_branch_services_duration', '`duration_override` IS NULL OR `duration_override` BETWEEN 5 AND 720');

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name', 120);
            $table->text('bio')->nullable();
            $table->string('photo')->nullable();
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->unsignedSmallInteger('buffer_minutes')->default(0)->comment('extra personal buffer between appointments');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });
        TenantSchema::checkIn('employees', 'status', RecordStatus::values());

        Schema::create('employee_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('branch_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'employee_id', 'employees', 'cascade');
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            $table->unique(['employee_id', 'branch_id']);
            $table->index(['branch_id', 'active']);
        });

        Schema::create('employee_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = all branches of the employee');
            $table->unsignedSmallInteger('duration_override')->nullable();
            $table->decimal('price_override', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'employee_id', 'employees', 'cascade');
            TenantSchema::foreign($table, 'service_id', 'services', 'cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['employee_id', 'service_id', 'branch_id']);
            $table->index(['tenant_id', 'service_id', 'active']);
        });
        TenantSchema::check('employee_services', 'chk_employee_services_duration', '`duration_override` IS NULL OR `duration_override` BETWEEN 5 AND 720');

        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('employee_id')->nullable()->comment('NULL = branch opening hours');
            $table->unsignedTinyInteger('day_of_week')->comment('0=Sunday .. 6=Saturday');
            $table->time('starts_local');
            $table->time('ends_local');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['branch_id', 'employee_id', 'day_of_week']);
        });
        TenantSchema::check('working_hours', 'chk_working_hours_dow', '`day_of_week` BETWEEN 0 AND 6');
        TenantSchema::check('working_hours', 'chk_working_hours_range', '`ends_local` > `starts_local`');
        TenantSchema::check('working_hours', 'chk_working_hours_effective', '`effective_to` IS NULL OR `effective_from` IS NULL OR `effective_to` >= `effective_from`');

        Schema::create('employee_breaks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_local');
            $table->time('ends_local');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            TenantSchema::foreign($table, 'employee_id', 'employees', 'cascade');
            $table->index(['employee_id', 'day_of_week']);
        });
        TenantSchema::check('employee_breaks', 'chk_employee_breaks_dow', '`day_of_week` BETWEEN 0 AND 6');
        TenantSchema::check('employee_breaks', 'chk_employee_breaks_range', '`ends_local` > `starts_local`');

        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->string('reason', 300)->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'employee_id', 'employees', 'cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['employee_id', 'starts_at_utc', 'ends_at_utc']);
        });
        TenantSchema::check('employee_leaves', 'chk_employee_leaves_range', '`ends_at_utc` > `starts_at_utc`');
        TenantSchema::checkIn('employee_leaves', 'status', ['requested', 'approved', 'rejected']);

        Schema::create('blocked_times', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('employee_id')->nullable()->comment('NULL = whole branch closed (holiday)');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->string('reason', 300)->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['branch_id', 'starts_at_utc', 'ends_at_utc']);
        });
        TenantSchema::check('blocked_times', 'chk_blocked_times_range', '`ends_at_utc` > `starts_at_utc`');
    }

    public function down(): void
    {
        foreach (['blocked_times', 'employee_leaves', 'employee_breaks', 'working_hours', 'employee_services', 'employee_branches', 'employees', 'branch_services', 'services', 'service_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
