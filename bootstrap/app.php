<?php

use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidTransitionException;
use App\Exceptions\PlanLimitException;
use App\Exceptions\TenantAccessDeniedException;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware([])->group(base_path('routes/webhooks.php'));
        },
    )
    // Listeners are registered explicitly (AppServiceProvider::boot) — auto-discovery would register them twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'subscribed' => EnsureActiveSubscription::class,
            'feature' => EnsurePlanFeature::class,
            'platform' => EnsurePlatformStaff::class,
            'noindex' => NoIndex::class,
            'idempotent' => IdempotentRequest::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class]);
        // Tenant context must exist before implicit route-model binding runs,
        // so bound models are already tenant-scoped (no cross-tenant IDOR).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->expectsJson() ? null : route('login'));
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES), so they survive config caching.
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'merchant_key', 'merchant_salt']);
        $exceptions->dontReport([BookingConflictException::class, DomainRuleException::class, InvalidTransitionException::class, PlanLimitException::class]);

        $isApi = fn (Request $r) => $r->is('api/*') || $r->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if (! $isApi($r)) {
                return null;
            }
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $errors[] = ['code' => 'validation_failed', 'field' => $field, 'message' => $m];
                }
            }

            return ApiResponse::error(422, $errors);
        });
        $exceptions->render(function (BookingConflictException $e, Request $r) use ($isApi) {
            return $isApi($r)
                ? ApiResponse::fail(409, 'slot_unavailable', $e->getMessage(), ['reason' => $e->reason])
                : back()->withInput()->withErrors(['booking' => $e->getMessage()]);
        });
        $exceptions->render(function (InvalidTransitionException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(409, 'invalid_transition', $e->getMessage()) : back()->withErrors(['booking' => $e->getMessage()]);
        });
        $exceptions->render(function (PlanLimitException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(403, 'plan_limit', $e->getMessage(), ['limit' => $e->limitKey]) : back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        });
        $exceptions->render(function (DomainRuleException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(422, $e->errorCode, $e->getMessage()) : back()->withInput()->withErrors(['booking' => $e->getMessage()]);
        });
        $exceptions->render(function (TenantAccessDeniedException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(403, 'tenant_forbidden', 'Bu kayda erişim yetkiniz yok.') : abort(403);
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(401, 'unauthenticated', 'Oturum açmanız gerekiyor.') : null;
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(403, 'forbidden', 'Bu işlem için yetkiniz yok.') : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(404, 'not_found', 'Kayıt bulunamadı.') : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail(429, 'rate_limited', 'Çok fazla istek. Lütfen biraz bekleyin.')->withHeaders($e->getHeaders()) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::fail($e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'İstek işlenemedi.') : null;
        });
    })->create();
