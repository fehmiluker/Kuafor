<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platform-level billing record (like Subscription): not tenant-scoped, read by provider callbacks. */
class SubscriptionCheckout extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['provider_token'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
