<?php

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hosted card checkout (PayTR iFrame) for one subscription period. The
 * provider's callback is matched to it by merchant_oid; the paid amount is the
 * amount frozen here, not whatever the callback claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('merchant_oid', 64)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 20)->default('pending');
            $table->string('provider_token', 255)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'status']);
        });
        TenantSchema::checkIn('subscription_checkouts', 'status', ['pending', 'succeeded', 'failed']);
        TenantSchema::check('subscription_checkouts', 'chk_checkout_amount', '`amount` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_checkouts');
    }
};
