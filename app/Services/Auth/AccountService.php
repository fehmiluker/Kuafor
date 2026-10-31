<?php

namespace App\Services\Auth;

use App\Enums\BillingInterval;
use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;

final class AccountService
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    /** @return array{user: User, tenant: ?Tenant} */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'], 'email' => $data['email'], 'phone_e164' => $data['phone'],
                'password' => $data['password'], 'locale' => 'tr', 'status' => 'active',
            ])->save();
            $user->inTeam((int) config('tenancy.platform_team_id'), fn (User $u) => $u->assignRole(PlatformRole::Customer->value));

            $tenant = null;
            if (! empty($data['business_name'])) {
                $plan = Plan::with('features')->where('slug', $data['plan'] ?? 'standart')->where('active', true)->firstOrFail();
                $tenant = $this->provisioner->provision($user, $data['business_name'], $plan, [], BillingInterval::Monthly);
            }

            return ['user' => $user, 'tenant' => $tenant];
        });
    }
}
