<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'settings' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'home_service_enabled' => 'boolean',
            'home_requires_approval' => 'boolean',
            'home_travel_minutes' => 'integer',
            'home_min_total' => MoneyCast::class,
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_branches')
            ->withPivot(['active', 'tenant_id'])->withTimestamps();
    }

    public function branchServices(): HasMany
    {
        return $this->hasMany(BranchService::class);
    }

    public function serviceAreas(): HasMany
    {
        return $this->hasMany(BranchServiceArea::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class)->whereNull('employee_id');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')->withPivot(['context', 'tenant_id'])->withTimestamps();
    }

    public function whatsappConnection(): HasOne
    {
        return $this->hasOne(WhatsAppConnection::class);
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    public function mapUrl(): ?string
    {
        if ($this->lat !== null && $this->lng !== null) {
            return 'https://www.openstreetmap.org/?mlat='.$this->lat.'&mlon='.$this->lng.'#map=17/'.$this->lat.'/'.$this->lng;
        }
        if ($this->address) {
            return 'https://www.openstreetmap.org/search?query='.urlencode($this->address);
        }

        return null;
    }
}
