<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ActsInTenant;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use ActsInTenant;

    public function test_security_page_requires_platform_role_and_mfa(): void
    {
        $this->get('/admin/guvenlik')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/guvenlik')->assertForbidden();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/admin/guvenlik')->assertRedirect(route('two-factor.challenge'));
        $this->withSession(['two_factor_passed' => true])->get('/admin/guvenlik')
            ->assertOk()->assertSee('Mevcut parola')->assertSee('Yeni parola tekrarı');
    }

    public function test_password_change_requires_mfa(): void
    {
        $admin = $this->superAdmin();
        $hash = $admin->password;
        $this->actingAs($admin)->put('/admin/guvenlik/parola', [
            'current_password' => 'password',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertRedirect(route('two-factor.challenge'));
        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_wrong_current_password_does_not_change_password(): void
    {
        $admin = $this->superAdmin();
        $hash = $admin->password;
        $this->actingAs($admin)->withSession(['two_factor_passed' => true])
            ->from('/admin/guvenlik')->put('/admin/guvenlik/parola', [
                'current_password' => 'wrong',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])->assertSessionHasErrors('current_password');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertNull(session()->getOldInput('current_password'));
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_new_password_must_be_strong_confirmed_and_different(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['password' => 'OldSecurePassword123!'])->save();
        $hash = $admin->password;
        $this->actingAs($admin)->withSession(['two_factor_passed' => true]);
        foreach ([['short', 'short'], ['NewSecurePassword123!', 'mismatch'], ['OldSecurePassword123!', 'OldSecurePassword123!']] as [$password, $confirmation]) {
            $this->put('/admin/guvenlik/parola', [
                'current_password' => 'OldSecurePassword123!',
                'password' => $password,
                'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
            $this->assertSame($hash, $admin->fresh()->password);
        }
    }

    public function test_password_change_logs_out_preserves_totp_and_records_no_passwords(): void
    {
        $admin = $this->superAdmin();
        $secret = $admin->two_factor_secret;
        $admin->forceFill(['password' => 'OldSecurePassword123!'])->save();
        $this->actingAs($admin)->withSession(['two_factor_passed' => true])
            ->put('/admin/guvenlik/parola', [
                'current_password' => 'OldSecurePassword123!',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();
        $this->assertFalse(session()->has('two_factor_passed'));
        $admin->refresh();
        $this->assertTrue(Hash::check('NewSecurePassword123!', $admin->password));
        $this->assertFalse(Hash::check('OldSecurePassword123!', $admin->password));
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.password_changed', 'target_id' => $admin->id,
            'before' => null, 'after' => null,
        ]);
    }
}
