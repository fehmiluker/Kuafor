<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform (super admin / support) area. Requires MFA: super admins must have
 * TOTP confirmed and have passed it in this session (web) or hold a token
 * issued after TOTP verification (API, ability "platform").
 */
class EnsurePlatformStaff
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isPlatformStaff()) {
            return $this->deny($request, 403, 'forbidden', 'Bu alana erişim yetkiniz yok.');
        }
        $token = $user->currentAccessToken();
        $isApiToken = $token instanceof PersonalAccessToken;
        if ($isApiToken) {
            // Explicit ability required: a wildcard ["*"] token must NOT satisfy MFA.
            if (! in_array('platform', (array) $token->abilities, true)) {
                return $this->deny($request, 403, 'mfa_required', 'Yönetim API erişimi için MFA ile oturum açın.');
            }
        } elseif (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        } elseif (! $request->session()->get('two_factor_passed')) {
            return redirect()->route('two-factor.challenge');
        }
        // Platform permissions live in Spatie team 0.
        $this->context->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId((int) config('tenancy.platform_team_id'));
        $user->unsetRelation('roles')->unsetRelation('permissions');

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
