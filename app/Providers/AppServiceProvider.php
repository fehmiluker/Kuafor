<?php

namespace App\Providers;

use App\Listeners\SendAppointmentNotifications;
use App\Services\Billing\ManualPaymentGateway;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\PaymentSettings;
use App\Services\Billing\PaytrPaymentGateway;
use App\Services\Catalog\SalonCards;
use App\Services\Tenancy\AccessScope;
use App\Services\Tenancy\TenantContext;
use App\Services\WhatsApp\FakeWhatsAppClient;
use App\Services\WhatsApp\MetaCloudApiClient;
use App\Services\WhatsApp\WhatsAppClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): reset between requests and between queue jobs (Octane/queue safe).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessScope::class);
        $this->app->scoped(SalonCards::class);

        $this->app->singleton(WhatsAppClientInterface::class, fn () => config('whatsapp.mode') === 'meta'
            ? new MetaCloudApiClient
            : new FakeWhatsAppClient);
        $this->app->scoped(PaymentGatewayInterface::class, fn () => match (app(PaymentSettings::class)->provider()) {
            'paytr' => PaytrPaymentGateway::fromConfig(),
            default => new ManualPaymentGateway,
        });
    }

    public function boot(): void
    {
        // Immutable dates everywhere: no accidental in-place mutation of booking times.
        Date::use(CarbonImmutable::class);
        Paginator::defaultView('pagination.default');

        Event::subscribe(SendAppointmentNotifications::class);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by('auth-ip:'.$r->ip()),
            Limit::perMinute(5)->by('auth-id:'.strtolower((string) ($r->input('email') ?? $r->input('phone')))),
        ]);
        RateLimiter::for('booking', fn (Request $r) => [
            Limit::perMinute(10)->by('book-ip:'.$r->ip()),
            Limit::perHour(30)->by('book-ip-h:'.$r->ip()),
        ]);
        RateLimiter::for('guest', fn (Request $r) => Limit::perMinute(30)->by('guest:'.$r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by('wh:'.$r->ip()));
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(5)->by('otp:'.$r->ip()));
    }
}
