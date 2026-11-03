<?php

namespace Tests\Feature;

use App\Exceptions\DomainRuleException;
use App\Livewire\Public\BookingWizard;
use App\Models\Appointment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Auth\PhoneVerificationService;
use App\Services\WhatsApp\WhatsAppClientInterface;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\Support\WhatsAppOtp;
use Tests\TestCase;

class WhatsAppOtpTest extends TestCase
{
    use ActsInTenant, BuildsSalon, WhatsAppOtp;

    public function test_code_is_sent_from_the_platform_number_with_the_authentication_template(): void
    {
        $platform = $this->connectPlatformWhatsApp();
        app(PhoneVerificationService::class)->send('+905551112233', 'account');

        $sent = app(WhatsAppClientInterface::class)->sentTo('+905551112233');
        $this->assertCount(1, $sent);
        $this->assertSame($platform->id, $sent[0]['connection_id']);
        $this->assertSame('dogrulama_kodu', $sent[0]['payload']['name']);
        $code = $this->lastOtp('+905551112233');
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]], $sent[0]['payload']['components'][1], 'copy-code button');

        $row = WhatsAppMessage::withoutGlobalScopes()->where('event_type', 'otp_code')->sole();
        $this->assertSame('sent', $row->status->value);
        $this->assertStringNotContainsString($code, json_encode($row->payload_redacted), 'the code is never stored with the message');
        $this->assertStringNotContainsString($code, (string) DB::table('phone_verifications')->value('code_hash'));

        $this->assertFalse(app(PhoneVerificationService::class)->verify('+905551112233', 'account', '000000'));
        $this->assertTrue(app(PhoneVerificationService::class)->verify('+905551112233', 'account', $code));
    }

    public function test_without_platform_number_or_approved_template_verification_is_unavailable(): void
    {
        try {
            app(PhoneVerificationService::class)->send('+905551112233', 'account');
            $this->fail('expected otp_unavailable');
        } catch (DomainRuleException $e) {
            $this->assertSame('otp_unavailable', $e->errorCode);
        }
        $this->assertSame(0, DB::table('phone_verifications')->count());

        $this->connectPlatformWhatsApp();
        DB::table('whatsapp_templates')->where('key', 'otp_code')->update(['approval_status' => 'PENDING']);
        $this->expectException(DomainRuleException::class);
        app(PhoneVerificationService::class)->send('+905551112233', 'account');
    }

    public function test_send_failure_is_reported_to_the_customer(): void
    {
        $this->connectPlatformWhatsApp();
        app(WhatsAppClientInterface::class)->failNext[] = new WhatsAppException('Recipient not on WhatsApp', '131026');
        try {
            app(PhoneVerificationService::class)->send('+905551112233', 'account');
            $this->fail('expected otp_send_failed');
        } catch (DomainRuleException $e) {
            $this->assertSame('otp_send_failed', $e->errorCode);
        }
        $this->assertSame('failed', WhatsAppMessage::withoutGlobalScopes()->where('event_type', 'otp_code')->sole()->status->value);
    }

    public function test_guest_home_visit_booking_with_whatsapp_code(): void
    {
        $this->buildSalon();
        $this->connectPlatformWhatsApp();
        DB::table('districts')->insertOrIgnore(['id' => 9001, 'city_id' => DB::table('cities')->insertGetId(['name' => 'Bursa', 'slug' => 'bursa', 'created_at' => now(), 'updated_at' => now()]), 'name' => 'Nilüfer', 'slug' => 'nilufer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->where('id', $this->branch->id)->update(['home_service_enabled' => true, 'home_requires_approval' => false]);
        DB::table('branch_service_areas')->insert(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'district_id' => 9001, 'travel_fee' => '0']);
        DB::table('services')->where('id', $this->cut->id)->update(['home_available' => true]);
        DB::table('employees')->where('id', $this->ayse->id)->update(['home_service' => true]);

        $w = Livewire::test(BookingWizard::class, ['slug' => $this->tenant->slug])->call('setLocation', 'address')
            ->call('toggleService', $this->cut->id)->call('toEmployees')->call('selectEmployee', null)->call('selectTime', '11:00')
            ->set('fullName', 'Zeynep Kaya')->set('phone', '0555 111 22 33')->set('kvkk', true)
            ->set('districtId', 9001)->set('addressLine', 'Fethiye Mah. Papatya Sk. No:5')
            ->assertSee('WhatsApp ile kod gönder')->call('sendCode')->assertSet('codeSent', true)->assertSee("WhatsApp'ınıza gönderildi");
        $w->set('code', $this->lastOtp('+905551112233'))->call('toSummary')->call('confirm')->assertSet('step', 7);
        $this->assertSame('address', Appointment::withoutGlobalScopes()->sole()->location_type);
    }

    public function test_profile_phone_verification_and_health_check(): void
    {
        $this->artisan('kuafor:check')->expectsOutputToContain('Telefon doğrulama kapalı');
        $this->connectPlatformWhatsApp();
        $u = User::factory()->create(['phone_e164' => '+905551112233', 'phone_verified_at' => null]);
        $this->actingAs($u)->post(route('account.phone.verify'))->assertSessionHas('status', 'Doğrulama kodu WhatsApp ile gönderildi.');
        $this->post(route('account.phone.verify'), ['code' => $this->lastOtp('+905551112233')])->assertSessionHasNoErrors();
        $this->assertNotNull($u->refresh()->phone_verified_at);
        $this->artisan('kuafor:check')->expectsOutputToContain('Telefon doğrulama: WhatsApp');
    }
}
