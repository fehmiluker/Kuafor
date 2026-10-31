<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Concerns\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'rating_avg', 'rating_count', 'status', 'suspended_at', 'suspension_reason', 'owner_user_id'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'public_listing_enabled' => 'boolean',
            'settings' => 'array',
            'tax_info' => 'encrypted',
            'rating_avg' => 'decimal:2',
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('status')->withTimestamps();
    }

    // Relations below bypass the TenantScope intentionally: the parent tenant fixes the scope.
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes([TenantScope::class]);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->withoutGlobalScopes([TenantScope::class]);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class)->withoutGlobalScopes([TenantScope::class]);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->withoutGlobalScopes([TenantScope::class]);
    }

    public function media(): HasMany
    {
        return $this->hasMany(TenantMedia::class)->withoutGlobalScopes([TenantScope::class])->orderBy('sort_order');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /** Tenants visible in public search: active, opted in, usable subscription. */
    public function scopePubliclyListed(Builder $q): Builder
    {
        return $q->where('tenants.status', TenantStatus::Active->value)
            ->where('tenants.public_listing_enabled', true)
            ->whereExists(fn ($s) => $s->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->whereIn('subscriptions.status', SubscriptionStatus::usable()));
    }

    /** Tenants that may receive bookings via direct link. */
    public function scopeBookable(Builder $q): Builder
    {
        return $q->where('tenants.status', TenantStatus::Active->value)
            ->whereExists(fn ($s) => $s->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->whereIn('subscriptions.status', SubscriptionStatus::usable()));
    }
}
