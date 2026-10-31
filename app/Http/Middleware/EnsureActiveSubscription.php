<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Http\Responses\ApiResponse;
use App\Services\Billing\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended tenant / unusable subscription => read-only panel. Reads stay
 * available so the business can export data and fix billing; writes are refused.
 */
class EnsureActiveSubscription
{
    public function __construct(private readonly TenantContext $context, private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->require();
        $usable = $tenant->status === TenantStatus::Active && $this->entitlements->isUsable($tenant);
        $request->attributes->set('subscription_usable', $usable);
        if ($usable || $request->isMethodSafe()) {
            return $next($request);
        }
        $message = $tenant->status === TenantStatus::Suspended
            ? 'İşletme hesabınız askıya alınmış. Lütfen destek ile iletişime geçin.'
            : 'Aboneliğiniz aktif değil. Değişiklik yapabilmek için aboneliğinizi yenileyin.';
        if ($request->expectsJson()) {
            return ApiResponse::fail(402, 'subscription_inactive', $message);
        }

        return redirect()->route('tenant.subscription')->withErrors(['subscription' => $message]);
    }
}
