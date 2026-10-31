<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\AccessScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

abstract class TenantApiController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->require();
    }

    protected function scope(): AccessScope
    {
        return app(AccessScope::class);
    }

    protected function branchIds(Request $request): ?array
    {
        return $this->scope()->branchIds($request->user(), $this->tenant());
    }
}
