<?php

namespace App\Models;

use App\Enums\SalonApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platform-level "Salonunu ekle" application (not tenant-scoped). */
class SalonApplication extends Model
{
    protected $guarded = ['id', 'status', 'tenant_id', 'decided_by_user_id', 'decided_at', 'rejection_reason'];

    protected function casts(): array
    {
        return [
            'status' => SalonApplicationStatus::class,
            'categories' => 'array',
            'decided_at' => 'immutable_datetime',
            'kvkk_acknowledged_at' => 'immutable_datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
