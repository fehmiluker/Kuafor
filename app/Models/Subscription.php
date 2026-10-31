<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'entitlements_snapshot' => 'array',
            'price_snapshot' => MoneyCast::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->latest('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class)->latest('id');
    }
}
