<?php

namespace App\Services\Auth;

use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Links past guest bookings (tenant CRM cards with the same phone) to an
 * account — ONLY after the account proved ownership of the number via OTP.
 */
final class CustomerLinker
{
    public function linkVerifiedPhone(User $user): int
    {
        if (! $user->phone_verified_at || ! $user->phone_e164) {
            return 0;
        }

        return DB::table('tenant_customers')->where('phone_e164', $user->phone_e164)->whereNull('user_id')
            ->update(['user_id' => $user->id, 'updated_at' => now()]);
    }

    /** @return list<int> tenant_customer ids belonging to the user */
    public function customerIds(User $user): array
    {
        return TenantCustomer::withoutGlobalScopes()->where('user_id', $user->id)->pluck('id')->all();
    }
}
