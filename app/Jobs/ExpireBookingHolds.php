<?php

namespace App\Jobs;

use App\Services\Booking\AppointmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Idempotent TTL sweep for pending requests, holds and proposals. */
class ExpireBookingHolds implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 120;

    public function handle(AppointmentService $appointments): void
    {
        $appointments->expireStale();
    }
}
