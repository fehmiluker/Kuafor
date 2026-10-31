<?php

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenant_settings' => 'tenant_id', 'branch_settings' => 'branch_id', 'platform_settings' => null] as $name => $owner) {
            Schema::create($name, function (Blueprint $table) use ($owner, $name) {
                $table->id();
                if ($owner === 'tenant_id') {
                    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                } elseif ($owner === 'branch_id') {
                    $table->unsignedBigInteger('tenant_id');
                    $table->unsignedBigInteger('branch_id');
                    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                    TenantSchema::foreign($table, 'branch_id', 'branches', 'cascade');
                }
                $table->string('key', 80);
                $table->text('value')->nullable();
                $table->string('type', 10)->default('string');
                $table->boolean('is_encrypted')->default(false);
                $table->timestamps();
                $table->unique($owner ? [$owner, 'key'] : ['key'], "{$name}_key_unique");
            });
            TenantSchema::checkIn($name, 'type', ['string', 'int', 'bool', 'json']);
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('action', 80);
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->string('key', 120);
            $table->string('request_hash', 64);
            $table->string('response_reference', 190)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['scope', 'key']);
            $table->index('expires_at');
        });

        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->string('note', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        TenantSchema::checkIn('data_subject_requests', 'type', ['export', 'delete', 'rectify']);
        TenantSchema::checkIn('data_subject_requests', 'status', ['open', 'in_progress', 'completed', 'rejected']);

        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('description', 2000);
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 1000)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['abuse_reports', 'data_subject_requests', 'idempotency_keys', 'audit_logs', 'platform_settings', 'branch_settings', 'tenant_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
