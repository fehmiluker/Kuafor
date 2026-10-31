<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for panel/API requests from the AUTHENTICATED user's
 * memberships. The client may only choose among tenants it belongs to
 * (X-Tenant header / session / signed link); a tenant_id in the payload is
 * never trusted. Must run before route model binding.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny($request, 401, 'unauthenticated', 'Oturum açmanız gerekiyor.');
        }
        $memberships = $user->activeTenants()->get(['tenants.id', 'tenants.slug', 'tenants.status']);
        $wanted = $request->header(config('tenancy.header'))
            ?? $request->query('tenant')
            ?? ($request->hasSession() ? $request->session()->get(config('tenancy.session_key')) : null);

        $tenantRow = $wanted !== null
            ? $memberships->first(fn ($t) => (string) $t->id === (string) $wanted || $t->slug === $wanted)
            : ($memberships->count() === 1 ? $memberships->first() : null);

        if (! $tenantRow) {
            if ($request->expectsJson()) {
                return $this->deny($request, 403, 'tenant_required', 'Geçerli bir işletme seçin (X-Tenant).');
            }

            return $memberships->isEmpty() ? redirect()->route('home') : redirect()->route('tenant.select');
        }
        $tenant = Tenant::findOrFail($tenantRow->id);
        if ($tenant->status === TenantStatus::Closed) {
            return $this->deny($request, 403, 'tenant_closed', 'Bu işletme hesabı kapatılmış.');
        }
        $this->context->set($tenant);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        if ($request->hasSession()) {
            $request->session()->put(config('tenancy.session_key'), $tenant->id);
        }

        return $next($request);
    }

    private function deny(Request $request, int $status, string $code, string $message): Response
    {
        if ($request->expectsJson()) {
            return ApiResponse::fail($status, $code, $message);
        }
        abort($status, $message);
    }
}
