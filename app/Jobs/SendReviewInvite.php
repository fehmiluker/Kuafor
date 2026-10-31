<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Review;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendReviewInvite implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $appointmentId)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $a = Appointment::withoutGlobalScopes()->find($this->appointmentId);
        if ($a && ! Review::withoutGlobalScopes()->where('appointment_id', $a->id)->exists()) {
            $dispatcher->reviewInvite($a); // dedupe key makes this idempotent
        }
    }
}
