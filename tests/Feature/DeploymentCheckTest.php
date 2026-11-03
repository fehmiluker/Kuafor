<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** `php artisan kuafor:check` — read-only hosting/health check used after cPanel installs. */
class DeploymentCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml sets fake Meta/billing secrets for the webhook tests; a demo server leaves them empty.
        config(['whatsapp.app_secret' => null, 'whatsapp.verify_token' => null, 'subscriptions.webhook_secret' => null, 'mail.default' => 'log']);
    }

    public function test_demo_check_passes_with_safe_defaults(): void
    {
        $this->artisan('kuafor:check', ['--demo' => true])
            ->expectsOutputToContain('Derlenmiş CSS/JS (public/build)')
            ->expectsOutputToContain('Demo: WhatsApp gerçek mesaj göndermiyor')
            ->expectsOutputToContain('Demo: gerçek ödeme kapalı')
            ->expectsOutputToContain('tüm zorunlu kontroller geçti')
            ->assertExitCode(0);
    }

    public function test_demo_check_fails_when_real_whatsapp_or_payment_is_enabled(): void
    {
        config(['whatsapp.mode' => 'meta']);
        $this->artisan('kuafor:check', ['--demo' => true])->expectsOutputToContain('WHATSAPP_PROVIDER_MODE=fake')->assertExitCode(1);

        config(['whatsapp.mode' => 'fake', 'subscriptions.webhook_secret' => 'set-on-purpose']);
        $this->artisan('kuafor:check', ['--demo' => true])->expectsOutputToContain('SUBSCRIPTION_WEBHOOK_SECRET boş')->assertExitCode(1);
    }

    public function test_stale_cron_heartbeat_is_reported(): void
    {
        Cache::put('kuafor:scheduler_heartbeat', now()->subMinutes(10)->toIso8601String());
        $this->artisan('kuafor:check')->expectsOutputToContain('schedule:run')->assertExitCode(1);

        config(['queue.default' => 'database']); // as on cPanel: worker runs from cron
        Cache::put('kuafor:scheduler_heartbeat', now()->toIso8601String());
        (new QueueHeartbeat)->handle();
        $this->artisan('kuafor:check')->expectsOutputToContain('Kuyruk işçisi (queue:work cron): son çalışma 0 dk önce')->assertExitCode(0);
    }

    public function test_heartbeats_are_scheduled_every_minute_without_touching_existing_tasks(): void
    {
        $events = collect(app(Schedule::class)->events());
        $names = $events->map(fn ($e) => $e->description ?? $e->command)->filter()->values()->all();
        $this->assertContains('scheduler-heartbeat', $names);
        $this->assertContains('queue-heartbeat', $names);
        foreach (['ExpireBookingHolds', 'SendAppointmentReminders', 'SweepSubscriptions', 'PurgeExpiredData'] as $job) {
            $this->assertTrue($events->contains(fn ($e) => str_contains((string) ($e->description ?? ''), $job)), "{$job} hâlâ zamanlanmış olmalı");
        }
    }
}
