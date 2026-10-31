<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Billing\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function __construct(private readonly TenantContext $context, private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->entitlements->hasFeature($this->context->require(), $feature)) {
            return $next($request);
        }
        $message = 'Bu özellik mevcut paketinizde yer almıyor. Paketinizi yükseltebilirsiniz.';
        if ($request->expectsJson()) {
            return ApiResponse::fail(403, 'feature_unavailable', $message, ['feature' => $feature]);
        }

        return redirect()->route('tenant.subscription')->withErrors(['feature' => $message]);
    }
}
