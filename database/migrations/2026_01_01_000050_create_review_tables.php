<?php

use App\Enums\ReviewStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('appointment_id')->unique()->comment('one review per appointment');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('employee_rating')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default(ReviewStatus::Published->value);
            $table->timestamp('verified_at');
            $table->timestamp('edit_locked_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches');
            TenantSchema::foreign($table, 'appointment_id', 'appointments');
            TenantSchema::foreign($table, 'customer_id', 'tenant_customers');
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        TenantSchema::checkIn('reviews', 'status', ReviewStatus::values());
        TenantSchema::check('reviews', 'chk_reviews_rating', '`rating` BETWEEN 1 AND 5');
        TenantSchema::check('reviews', 'chk_reviews_employee_rating', '`employee_rating` IS NULL OR `employee_rating` BETWEEN 1 AND 5');

        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_type', 20)->default('tenant')->comment('tenant|customer|public');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->boolean('is_appeal')->default(false);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->unique(['review_id', 'reporter_user_id', 'is_appeal']);
        });
        TenantSchema::checkIn('review_reports', 'status', ['open', 'upheld', 'dismissed']);

        Schema::create('review_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->string('reason', 1000);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['review_moderation_logs', 'review_reports', 'review_replies', 'reviews'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
