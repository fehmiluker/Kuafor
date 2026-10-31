<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monthly_price' => MoneyCast::class,
            'yearly_price' => MoneyCast::class,
            'active' => 'boolean',
            'public' => 'boolean',
            'trial_days' => 'integer',
            'max_branches' => 'integer',
            'max_employees' => 'integer',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function priceFor(BillingInterval $interval): Money
    {
        return $interval === BillingInterval::Yearly ? $this->yearly_price : $this->monthly_price;
    }

    public function hasFeature(string $key): bool
    {
        return (bool) $this->features->firstWhere('feature_key', $key)?->enabled;
    }

    /** Entitlements copied into subscriptions.entitlements_snapshot at period start. */
    public function entitlements(): array
    {
        return [
            'plan_slug' => $this->slug,
            'max_branches' => $this->max_branches,
            'max_employees' => $this->max_employees,
            'features' => $this->features->mapWithKeys(fn ($f) => [$f->feature_key => (bool) $f->enabled])->all(),
        ];
    }
}
