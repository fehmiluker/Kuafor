<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ApprovalMode;
use App\Enums\HoldStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProposalStatus;
use App\Enums\RecordStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('phone_e164', 20);
            $table->string('email', 190)->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->text('notes')->nullable()->comment('encrypted cast, staff-only');
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            // Merge policy: one CRM card per phone number per tenant.
            $table->unique(['tenant_id', 'phone_e164']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'full_name']);
        });
        TenantSchema::checkIn('tenant_customers', 'status', RecordStatus::values());

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 16)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('employee_id');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc')->comment('service end, excluding buffer');
            $table->dateTime('blocked_until_utc')->comment('ends_at + buffers; used for conflict checks');
            $table->string('status', 30)->default(AppointmentStatus::Pending->value);
            $table->string('approval_mode_snapshot', 20);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            $table->string('customer_note', 1000)->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->dateTime('pending_expires_at_utc')->nullable();
            $table->string('source', 20)->default(AppointmentSource::Web->value);
            $table->string('idempotency_key', 80)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches');
            TenantSchema::foreign($table, 'customer_id', 'tenant_customers');
            TenantSchema::foreign($table, 'employee_id', 'employees');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'employee_id', 'starts_at_utc'], 'appt_tenant_branch_emp_start');
            $table->index(['tenant_id', 'status', 'starts_at_utc'], 'appt_tenant_status_start');
            $table->index(['employee_id', 'status', 'starts_at_utc', 'blocked_until_utc'], 'appt_emp_conflict');
            $table->index(['tenant_id', 'customer_id', 'starts_at_utc']);
        });
        TenantSchema::checkIn('appointments', 'status', AppointmentStatus::values());
        TenantSchema::checkIn('appointments', 'approval_mode_snapshot', ApprovalMode::values());
        TenantSchema::checkIn('appointments', 'source', AppointmentSource::values());
        TenantSchema::check('appointments', 'chk_appointments_range', '`ends_at_utc` > `starts_at_utc` AND `blocked_until_utc` >= `ends_at_utc`');
        TenantSchema::check('appointments', 'chk_appointments_money', '`subtotal` >= 0 AND `discount` >= 0 AND `total_price` >= 0 AND `discount` <= `subtotal`');

        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('service_name_snapshot', 150);
            $table->unsignedSmallInteger('duration_minutes_snapshot');
            $table->unsignedSmallInteger('buffer_minutes_snapshot')->default(0);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'appointment_id', 'appointments', 'cascade');
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });

        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source', 30);
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'appointment_id', 'appointments', 'cascade');
            $table->index(['appointment_id', 'created_at']);
        });

        Schema::create('appointment_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('proposed_by_user_id')->nullable();
            $table->dateTime('proposed_start_utc');
            $table->dateTime('proposed_end_utc');
            $table->dateTime('proposed_blocked_until_utc');
            $table->unsignedBigInteger('employee_id');
            $table->string('status', 20)->default(ProposalStatus::Open->value);
            $table->dateTime('expires_at_utc');
            $table->dateTime('responded_at_utc')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('note', 500)->nullable();
            // Enforces "at most one open proposal per appointment" at the DB level.
            $table->unsignedBigInteger('open_appointment_key')->nullable()
                ->storedAs("IF(`status` = 'open', `appointment_id`, NULL)");
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // RESTRICT (not CASCADE): MySQL forbids cascading FKs on the base column of a
            // stored generated column. Appointments are never hard-deleted.
            TenantSchema::foreign($table, 'appointment_id', 'appointments', 'restrict');
            TenantSchema::foreign($table, 'employee_id', 'employees');
            $table->foreign('proposed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique('open_appointment_key');
            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'expires_at_utc']);
        });
        TenantSchema::checkIn('appointment_proposals', 'status', ProposalStatus::values());

        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc')->comment('includes buffers');
            $table->dateTime('expires_at_utc');
            $table->string('status', 20)->default(HoldStatus::Held->value);
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            TenantSchema::foreign($table, 'employee_id', 'employees', 'cascade');
            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('proposal_id')->references('id')->on('appointment_proposals')->cascadeOnDelete();
            $table->index(['employee_id', 'status', 'starts_at_utc', 'ends_at_utc'], 'holds_emp_conflict');
            $table->index(['status', 'expires_at_utc']);
        });
        TenantSchema::checkIn('booking_holds', 'status', HoldStatus::values());
        TenantSchema::check('booking_holds', 'chk_booking_holds_range', '`ends_at_utc` > `starts_at_utc`');

        Schema::create('resource_day_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('resource_id');
            $table->date('local_date');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'resource_type', 'resource_id', 'local_date'], 'resource_day_lock_unique');
        });

        Schema::create('guest_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id');
            $table->string('token_hash', 64)->unique()->comment('sha256 of the plain token; plain token is never stored');
            $table->json('scopes');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'appointment_id', 'appointments', 'cascade');
        });

        Schema::create('action_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 40);
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('recipient_hash', 64)->nullable()->comment('HMAC of allowed recipient phone; no PII');
            $table->json('payload')->nullable()->comment('non-PII context, e.g. offered slot');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('proposal_id')->references('id')->on('appointment_proposals')->cascadeOnDelete();
            $table->index(['appointment_id', 'purpose']);
        });

        Schema::create('appointment_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('appointment_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'appointment_id', 'appointments', 'cascade');
            $table->index(['tenant_id', 'paid_at']);
        });
        TenantSchema::checkIn('appointment_payments', 'method', PaymentMethod::values());
        TenantSchema::check('appointment_payments', 'chk_appointment_payments_amount', '`amount` > 0');

        Schema::create('customer_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id']);
        });

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 20);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['phone_e164', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['phone_verifications', 'customer_favorites', 'appointment_payments', 'action_tokens', 'guest_access_tokens', 'resource_day_locks', 'booking_holds', 'appointment_proposals', 'appointment_status_histories', 'appointment_services', 'appointments', 'tenant_customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
