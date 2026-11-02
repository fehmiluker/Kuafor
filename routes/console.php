<?php

use App\Jobs\ExpireBookingHolds;
use App\Jobs\PurgeExpiredData;
use App\Jobs\QueueHeartbeat;
use App\Jobs\SendAppointmentReminders;
use App\Jobs\SweepSubscriptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Single-instance scheduling (onOneServer needs a shared cache store: database/redis).
Schedule::job(new ExpireBookingHolds)->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::job(new SendAppointmentReminders)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::job(new SweepSubscriptions)->hourly()->onOneServer()->withoutOverlapping();
Schedule::job(new PurgeExpiredData)->dailyAt('03:30')->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('auth:clear-resets invites')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Health heartbeats used by `php artisan kuafor:check` (cron and queue worker are alive).
Schedule::call(fn () => Cache::put('kuafor:scheduler_heartbeat', now()->toIso8601String(), now()->addDay()))
    ->everyMinute()->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
