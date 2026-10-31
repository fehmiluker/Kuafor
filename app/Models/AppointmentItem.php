<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A service line of an appointment (table appointment_services); snapshots name/duration/price. */
class AppointmentItem extends Model
{
    use BelongsToTenant;

    protected $table = 'appointment_services';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price_snapshot' => MoneyCast::class, 'duration_minutes_snapshot' => 'integer', 'buffer_minutes_snapshot' => 'integer'];
    }

    public function getCurrencyAttribute(): string
    {
        return 'TRY';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }
}
