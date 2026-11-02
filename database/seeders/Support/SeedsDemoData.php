<?php

namespace Database\Seeders\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Shared helpers for the demo seeders (demo users and time travel for past appointments). */
trait SeedsDemoData
{
    public const PASSWORD = 'Demo12345!';

    protected function user(string $name, string $email, string $phone): User
    {
        $u = User::where('email', $email)->first() ?? new User;
        $u->forceFill(['name' => $name, 'email' => $email, 'phone_e164' => $phone, 'password' => self::PASSWORD,
            'email_verified_at' => now(), 'phone_verified_at' => now(), 'locale' => 'tr', 'status' => 'active'])->save();
        $u->inTeam((int) config('tenancy.platform_team_id'), fn (User $x) => $x->syncRoles(['customer']));

        return $u;
    }

    protected function travel(CarbonImmutable $to, \Closure $fn): void
    {
        CarbonImmutable::setTestNow($to);
        Carbon::setTestNow($to);
        try {
            $fn();
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }
}
