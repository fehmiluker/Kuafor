<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\ApprovalMode;
use App\Enums\ProposalStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use BelongsToTenant, HasFactory;

    // Status, timestamps and money are only changed through AppointmentService.
    protected $guarded = ['id', 'version'];

    protected $hidden = ['internal_note', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'approval_mode_snapshot' => ApprovalMode::class,
            'source' => AppointmentSource::class,
            'starts_at_utc' => 'immutable_datetime',
            'ends_at_utc' => 'immutable_datetime',
            'blocked_until_utc' => 'immutable_datetime',
            'pending_expires_at_utc' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'total_price' => MoneyCast::class,
            'travel_fee' => MoneyCast::class,
            'travel_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function address(): HasOne
    {
        return $this->hasOne(AppointmentAddress::class);
    }

    public function isHomeVisit(): bool
    {
        return $this->location_type === 'address';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class)->orderBy('sort_order');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AppointmentProposal::class)->latest('id');
    }

    public function openProposal(): HasOne
    {
        return $this->hasOne(AppointmentProposal::class)->where('status', ProposalStatus::Open->value)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AppointmentPayment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(BookingHold::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', AppointmentStatus::active());
    }

    public function timezone(): string
    {
        return $this->branch?->timezone ?? config('tenancy.default_timezone');
    }

    public function localStart(): CarbonImmutable
    {
        return LocalTime::toLocal($this->starts_at_utc, $this->timezone());
    }

    public function localEnd(): CarbonImmutable
    {
        return LocalTime::toLocal($this->ends_at_utc, $this->timezone());
    }

    public function serviceSummary(): string
    {
        return $this->items->pluck('service_name_snapshot')->implode(', ');
    }

    public function paidTotal(): Money
    {
        return $this->payments->reduce(fn ($c, $p) => $c->plus($p->amount), Money::zero($this->currency));
    }
}
