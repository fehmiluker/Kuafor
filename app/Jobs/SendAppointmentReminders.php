<?php

namespace App\Jobs;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Services\Booking\BookingSettings;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends reminders for confirmed appointments whose reminder time has come.
 * Re-checks status at send time; dedupe keys include the start time, so a
 * rescheduled appointment gets a fresh reminder and nothing is sent twice.
 */
class SendAppointmentReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function handle(NotificationDispatcher $dispatcher, TenantContext $context): void
    {
        $now = now()->utc();
        Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at_utc', '>', $now)
            ->where('starts_at_utc', '<=', $now->addHours(48))
            ->with(['branch' => fn ($q) => $q->withoutGlobalScopes()])
            ->chunkById(200, function ($appointments) use ($dispatcher, $context, $now) {
                foreach ($appointments as $a) {
                    $tenant = Tenant::find($a->tenant_id);
                    if (! $tenant || ! $a->branch) {
                        continue;
                    }
                    $hours = BookingSettings::for($tenant, $a->branch)->reminderHoursBefore;
                    if ($a->starts_at_utc->subHours($hours)->gt($now)) {
                        continue;
                    }
                    $context->run($tenant, fn () => $dispatcher->reminder($a));
                }
            });
    }
}
