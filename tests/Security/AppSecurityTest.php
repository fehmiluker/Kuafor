<?php

namespace Tests\Security;

use App\DTOs\Appointments\Actor;
use App\Enums\TenantRole;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Booking\AppointmentService;
use App\Services\Reviews\ReviewService;
use App\Services\Tenancy\AccessScope;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Totp;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AppSecurityTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    public function test_stored_xss_is_escaped_on_public_pages(): void
    {
        $this->buildSalon();
        $this->tenant->forceFill(['description' => '<script>alert(1)</script><img src=x onerror=alert(2)>'])->save();
        $this->get('/salon/'.$this->tenant->slug)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_review_body_is_sanitized(): void
    {
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->travelTo($this->at('10:40'));
        $a = app(AppointmentService::class)->complete($a, Actor::system('t'));
        $r = app(ReviewService::class)->create($a, 5, null, '<b onclick="x()">Süper</b><script>steal()</script>');
        $this->assertStringNotContainsString('<script>', $r->body);
        $this->assertStringNotContainsString('onclick', $r->body);
    }

    public function test_upload_rejects_non_images_and_spoofed_mime(): void
    {
        Storage::fake('public');
        $this->buildSalon();
        $owner = $this->owner($this->tenant);
        $php = UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>');
        $this->actingAs($owner)->post('/panel/ayarlar/medya', ['kind' => 'logo', 'image' => $php])->assertSessionHasErrors('image');
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg onload="alert(1)"></svg>');
        $this->actingAs($owner)->post('/panel/ayarlar/medya', ['kind' => 'logo', 'image' => $svg])->assertSessionHasErrors('image');
        $ok = UploadedFile::fake()->image('logo.png', 300, 300);
        $this->actingAs($owner)->post('/panel/ayarlar/medya', ['kind' => 'logo', 'image' => $ok])->assertSessionHasNoErrors();
        $path = $this->tenant->fresh()->logo_path;
        $this->assertMatchesRegularExpression('#^tenants/\d+/media/[A-Za-z0-9]{40}\.jpg$#', $path, 'random name, re-encoded');
        Storage::disk('public')->assertExists($path);
    }

    public function test_csrf_is_enforced_on_web_mutations(): void
    {
        $this->buildSalon();
        $owner = $this->owner($this->tenant);
        // Laravel skips CSRF while running unit tests; bind a variant that does not.
        $this->app->instance(ValidateCsrfToken::class,
            new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $response = $this->actingAs($owner)->call('POST', '/panel/ayarlar', ['name' => 'Hacked']);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertNotSame('Hacked', $this->tenant->fresh()->name);
    }

    public function test_otp_brute_force_is_locked_and_sends_are_rate_limited(): void
    {
        $otp = app(PhoneVerificationService::class);
        $otp->send('+905551112233', 'booking');
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($otp->verify('+905551112233', 'booking', '000000'));
        }
        try {
            $otp->verify('+905551112233', 'booking', '000000');
            $this->fail('not locked');
        } catch (DomainRuleException $e) {
            $this->assertSame('otp_locked', $e->errorCode);
        }
        for ($i = 0; $i < 4; $i++) {
            $otp->send('+905551112233', 'booking');
        }
        $this->expectException(DomainRuleException::class);
        $otp->send('+905551112233', 'booking'); // 6th send within the hour
    }

    public function test_login_is_rate_limited_and_does_not_enumerate_users(): void
    {
        User::factory()->create(['email' => 'real@example.com']);
        $a = $this->postJson('/api/v1/auth/login', ['email' => 'real@example.com', 'password' => 'wrong'])->json('errors.0');
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'wrong'])->json('errors.0');
        $this->assertSame($a, $b);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'real@example.com', 'password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'real@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_super_admin_api_requires_mfa(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['email' => 'admin@example.com', 'password' => 'Secret12345'])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'Secret12345'])->assertStatus(401)->assertJsonPath('errors.0.code', 'mfa_required');
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'Secret12345', 'otp' => Totp::code($admin->two_factor_secret)])
            ->assertOk()->json('meta.token');
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertOk();
        // A token without the "platform" ability is refused even for a super admin.
        $plain = $admin->createToken('no-mfa', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_platform_support_is_restricted(): void
    {
        $support = User::factory()->create();
        $support->inTeam(0, fn ($u) => $u->assignRole('platform_support'));
        $token = $support->createToken('t', ['*', 'platform'])->plainTextToken;
        $this->buildSalon();
        $this->withToken($token)->getJson('/api/v1/admin/tenants')->assertOk();
        $this->withToken($token)->patchJson('/api/v1/admin/plans/1', ['monthly_price' => '1'])->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/admin/tenants/{$this->tenant->id}/customers?reason=Merak ettim sadece")->assertForbidden();
    }

    public function test_super_admin_pii_access_requires_reason_and_is_logged(): void
    {
        $this->buildSalon('automatic');
        $this->book($this->at('10:00'));
        $admin = $this->superAdmin();
        $token = $admin->createToken('t', ['*', 'platform'])->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/admin/tenants/{$this->tenant->id}/customers")->assertStatus(422);
        $this->withToken($token)->getJson("/api/v1/admin/tenants/{$this->tenant->id}/customers?reason=Hukuki talep 2026/123")->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.pii_access', 'actor_id' => $admin->id, 'reason' => 'Hukuki talep 2026/123']);
    }

    public function test_security_headers_and_noindex(): void
    {
        $this->buildSalon();
        $this->get('/')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/randevu/ABC?token=x')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_credentials_never_serialize(): void
    {
        $this->buildSalon();
        $c = WhatsAppConnection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'encrypted_credential_reference' => 'SECRET-TOKEN', 'status' => 'connected']);
        $this->assertArrayNotHasKey('encrypted_credential_reference', $c->toArray());
        $this->assertStringNotContainsString('SECRET-TOKEN', (string) \DB::table('whatsapp_connections')->value('encrypted_credential_reference'), 'stored encrypted');
        $owner = $this->owner($this->tenant);
        $this->assertStringNotContainsString('SECRET', $this->api($owner, $this->tenant)->getJson('/api/v1/tenant/whatsapp/connections')->getContent());
    }

    public function test_deactivated_member_loses_access(): void
    {
        $this->buildSalon();
        $rec = $this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]);
        $this->api($rec, $this->tenant)->getJson('/api/v1/tenant/appointments')->assertOk();
        app(TenantProvisioner::class)->removeMember($this->tenant, $rec);
        app(AccessScope::class)->flush();
        $this->api($rec->fresh(), $this->tenant)->getJson('/api/v1/tenant/appointments')->assertForbidden();
    }
}
