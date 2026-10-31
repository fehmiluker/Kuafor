<?php

namespace App\Services\Tenancy;

use App\Enums\BillingInterval;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates a tenant with its owner membership, owner role and trial subscription. */
final class TenantProvisioner
{
    public function __construct(private readonly SubscriptionService $subscriptions, private readonly TenantContext $context) {}

    public function provision(User $owner, string $name, Plan $plan, array $attributes = [], BillingInterval $interval = BillingInterval::Monthly): Tenant
    {
        return DB::transaction(function () use ($owner, $name, $plan, $attributes, $interval) {
            $tenant = new Tenant;
            $tenant->forceFill(array_merge([
                'name' => $name,
                'slug' => $this->uniqueSlug($attributes['slug'] ?? $name),
                'timezone' => config('tenancy.default_timezone'),
                'public_listing_enabled' => true,
                'status' => 'active',
                'owner_user_id' => $owner->id,
            ], array_diff_key($attributes, ['slug' => 1])))->save();

            $this->addMember($tenant, $owner, TenantRole::Owner);
            $this->subscriptions->startTrial($tenant, $plan, $interval, $owner->id);

            return $tenant;
        });
    }

    public function addMember(Tenant $tenant, User $user, TenantRole $role, array $branchIds = []): void
    {
        DB::table('tenant_user')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['status' => 'active', 'updated_at' => now(), 'created_at' => now()],
        );
        $user->inTeam($tenant->id, fn (User $u) => $u->syncRoles([$role->value]));
        DB::table('branch_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
        foreach (array_unique($branchIds) as $branchId) {
            DB::table('branch_user')->insert([
                'tenant_id' => $tenant->id, 'branch_id' => $branchId, 'user_id' => $user->id,
                'context' => $role->value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(AccessScope::class)->flush();
    }

    public function removeMember(Tenant $tenant, User $user): void
    {
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['status' => 'inactive', 'updated_at' => now()]);
        $user->inTeam($tenant->id, fn (User $u) => $u->syncRoles([]));
        DB::table('branch_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
        $user->tokens()->where('name', 'like', 'tenant:'.$tenant->id.':%')->delete();
        app(AccessScope::class)->flush();
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'salon';
        $slug = $base;
        $i = 2;
        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
