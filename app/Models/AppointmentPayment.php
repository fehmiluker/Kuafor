<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** In-salon payment record (reporting only). No payment gateway is involved. */
class AppointmentPayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'method' => PaymentMethod::class, 'paid_at' => 'immutable_datetime'];
    }

    public function getCurrencyAttribute(): string
    {
        return 'TRY';
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
