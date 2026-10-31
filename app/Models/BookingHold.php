<?php

namespace App\Models;

use App\Enums\HoldStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BookingHold extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'starts_at_utc' => 'immutable_datetime',
            'ends_at_utc' => 'immutable_datetime',
            'expires_at_utc' => 'immutable_datetime',
        ];
    }
}
