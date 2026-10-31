<?php

namespace App\Jobs;

use App\Services\Billing\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SweepSubscriptions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscriptions->sweep();
    }
}
