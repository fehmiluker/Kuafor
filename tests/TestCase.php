<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\TestBaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = TestBaseSeeder::class;

    protected function plan(string $slug = 'profesyonel'): Plan
    {
        return Plan::with('features')->where('slug', $slug)->firstOrFail();
    }

    /** Run assertions/queries inside a tenant context. */
    protected function inTenant(Tenant $tenant, \Closure $fn): mixed
    {
        return app(TenantContext::class)->run($tenant, $fn);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
