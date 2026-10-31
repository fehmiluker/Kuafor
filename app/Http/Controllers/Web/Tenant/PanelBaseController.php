<?php

namespace App\Http\Controllers\Web\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\AccessScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

abstract class PanelBaseController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->require();
    }

    protected function scope(): AccessScope
    {
        return app(AccessScope::class);
    }

    protected function allows(Request $request, string $permission): bool
    {
        return $request->user()->inTeam($this->tenant()->id, fn ($u) => $u->hasPermissionTo($permission));
    }

    protected function need(Request $request, string $permission): void
    {
        abort_unless($this->allows($request, $permission), 403);
    }
}
