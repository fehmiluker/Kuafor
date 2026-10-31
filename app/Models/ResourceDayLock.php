<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lock rows only; see App\Services\Booking\BookingLockService. */
class ResourceDayLock extends Model
{
    protected $guarded = ['id'];
}
