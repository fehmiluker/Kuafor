<?php

use App\Enums\ConsentStatus;
use App\Enums\MessageStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\WhatsAppConnectionStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id')->unique();
            $table->string('waba_id', 40)->nullable();
            $table->string('phone_number_id', 40)->nullable()->unique();
            $table->string('display_phone', 20)->nullable()->comment('E.164 sender number');
            $table->text('encrypted_credential_reference')->nullable()->comment('encrypted access token or secret-manager reference');
            $table->string('status', 20)->default(WhatsAppConnectionStatus::Pending->value);
            $table->json('permissions')->nullable();
            $table->boolean('webhook_subscribed')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
        });
        TenantSchema::checkIn('whatsapp_connections', 'status', WhatsAppConnectionStatus::values());

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60)->comment('internal event key, e.g. appointment_confirmed');
            $table->string('name', 120)->comment('Meta template name');
            $table->string('language', 10)->default('tr');
            $table->string('category', 20)->default('UTILITY');
            $table->string('provider_template_id', 60)->nullable();
            $table->string('approval_status', 20)->default('PENDING');
            $table->json('parameters_schema')->nullable();
            $table->text('fallback_body')->nullable()->comment('Turkish text used for email/panel fallback & sandbox preview');
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'language']);
        });
        TenantSchema::checkIn('whatsapp_templates', 'category', ['UTILITY', 'MARKETING', 'AUTHENTICATION']);
        TenantSchema::checkIn('whatsapp_templates', 'approval_status', ['PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED']);

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->string('recipient_e164', 20);
            $table->string('direction', 10);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('provider_message_id', 120)->nullable()->unique()->comment('wamid');
            $table->string('idempotency_key', 120)->unique();
            $table->string('message_type', 20);
            $table->string('event_type', 60)->nullable();
            $table->string('status', 20)->default(MessageStatus::Queued->value);
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('payload_redacted')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
            $table->foreign('connection_id')->references('id')->on('whatsapp_connections')->nullOnDelete();
            $table->foreign('template_id')->references('id')->on('whatsapp_templates')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        TenantSchema::checkIn('whatsapp_messages', 'direction', ['outbound', 'inbound']);
        TenantSchema::checkIn('whatsapp_messages', 'status', MessageStatus::values());

        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_key', 190)->unique()->comment('dedupe key: wamid+status or entry hash');
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->longText('payload_encrypted_or_redacted');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 20)->default('received');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->foreign('connection_id')->references('id')->on('whatsapp_connections')->nullOnDelete();
            $table->index(['status', 'received_at']);
        });
        TenantSchema::checkIn('whatsapp_webhook_events', 'status', ['received', 'processing', 'processed', 'failed', 'ignored']);

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_customer_id')->nullable()->constrained('tenant_customers')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('category', 30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'tenant_customer_id', 'channel', 'category'], 'notif_pref_unique');
        });
        TenantSchema::checkIn('notification_preferences', 'channel', NotificationChannel::values());
        TenantSchema::checkIn('notification_preferences', 'category', NotificationCategory::values());

        Schema::create('communication_consents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->string('source', 40);
            $table->timestamp('captured_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->json('proof_metadata')->nullable()->comment('text version, ip hash, ua hash');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'tenant_id', 'purpose', 'channel'], 'consent_lookup');
        });
        TenantSchema::checkIn('communication_consents', 'status', ConsentStatus::values());
        TenantSchema::checkIn('communication_consents', 'purpose', NotificationCategory::values());

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('recipient', 190)->comment('masked recipient');
            $table->string('event_type', 60);
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('dedupe_key', 190)->nullable()->unique();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'channel', 'status']);
        });
        TenantSchema::checkIn('notification_deliveries', 'channel', NotificationChannel::values());

        Schema::create('panel_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('title', 200);
            $table->string('body', 1000)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['panel_notifications', 'notification_deliveries', 'communication_consents', 'notification_preferences', 'whatsapp_webhook_events', 'whatsapp_messages', 'whatsapp_templates', 'whatsapp_connections'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
