<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Dispatched every minute by the scheduler; proves the queue worker is running (see kuafor:check). */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const KEY = 'kuafor:queue_heartbeat';

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(self::KEY, now()->toIso8601String(), now()->addDay());
    }
}
