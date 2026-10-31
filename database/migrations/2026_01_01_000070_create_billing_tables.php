<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->decimal('monthly_price', 12, 2);
            $table->decimal('yearly_price', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->unsignedSmallInteger('max_branches');
            $table->unsignedSmallInteger('max_employees');
            $table->boolean('active')->default(true);
            $table->boolean('public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        TenantSchema::check('plans', 'chk_plans_prices', '`monthly_price` >= 0 AND `yearly_price` >= 0');
        TenantSchema::check('plans', 'chk_plans_limits', '`max_branches` >= 1 AND `max_employees` >= 1');

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->boolean('enabled')->default(true);
            $table->string('limit_value', 60)->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('billing_interval', 10)->default(BillingInterval::Monthly->value);
            $table->json('entitlements_snapshot')->comment('limits+features frozen at period start');
            $table->decimal('price_snapshot', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('pending_billing_interval', 10)->nullable();
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 120)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });
        TenantSchema::checkIn('subscriptions', 'status', SubscriptionStatus::values());
        TenantSchema::checkIn('subscriptions', 'billing_interval', BillingInterval::values());

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status', 20)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
        });
        TenantSchema::checkIn('subscription_invoices', 'status', ['open', 'paid', 'void', 'uncollectible']);
        TenantSchema::check('subscription_invoices', 'chk_invoice_amount', '`amount` >= 0 AND `tax_amount` >= 0');

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('subscription_invoices')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('status', 20);
            $table->string('provider', 30);
            $table->string('provider_reference', 120)->nullable();
            $table->string('provider_event_id', 120)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->json('raw_redacted')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });
        TenantSchema::checkIn('subscription_payments', 'status', ['pending', 'succeeded', 'failed', 'refunded']);
    }

    public function down(): void
    {
        foreach (['subscription_payments', 'subscription_invoices', 'subscription_events', 'subscriptions', 'plan_features', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
