<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Creates the first super admin with a random password shown ONCE. MFA is enforced at first login. */
class CreateSuperAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Platform Yöneticisi} {--role=super_admin : super_admin|platform_support}';

    protected $description = 'Güvenli rastgele parola ile platform yöneticisi oluşturur';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Geçersiz e-posta.');

            return self::FAILURE;
        }
        $role = PlatformRole::tryFrom((string) $this->option('role'));
        if (! in_array($role, [PlatformRole::SuperAdmin, PlatformRole::Support], true)) {
            $this->error('Rol super_admin veya platform_support olmalı.');

            return self::FAILURE;
        }
        $password = Str::password(24);
        $user = User::where('email', $email)->first() ?? new User;
        $user->forceFill(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'status' => 'active', 'email_verified_at' => now()])->save();
        $user->inTeam((int) config('tenancy.platform_team_id'), fn (User $u) => $u->syncRoles([$role->value]));
        $audit->log('platform.admin_created', $user, [], ['role' => $role->value]);

        $this->info("Yönetici hazır: {$email} ({$role->value})");
        $this->warn("Geçici parola (yalnızca bir kez gösterilir): {$password}");
        $this->line('İlk girişte iki adımlı doğrulama (TOTP) kurulumu zorunludur.');

        return self::SUCCESS;
    }
}
