<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\District;
use App\Models\User;
use App\Services\Auth\CustomerLinker;
use App\Services\Sms\SmsProviderInterface;
use Database\Seeders\GeoSeeder;
use Database\Seeders\Support\SalonBuilder;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class SearchAndAccountTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoSeeder::class);
    }

    public function test_search_filters(): void
    {
        $this->buildSalon();
        $ist = City::where('slug', 'istanbul')->first();
        $this->branch->forceFill(['city_id' => $ist->id, 'district_id' => District::where('slug', 'kadikoy')->value('id')])->save();
        $ank = SalonBuilder::make()->tenant('Ankara Berber', $this->plan('standart'));
        $ab = $ank->branch('Kızılay', ['city_id' => City::where('slug', 'ankara')->value('id')]);
        $ank->service('Sakal', 20, '150.00', [$ab], 0, 'sakal');
        $ank->employee('Ali', [$ab], []);

        $names = fn ($q) => collect($this->getJson('/api/v1/salons?'.$q)->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Işıltı Kuaför'], $names('city=istanbul'));
        $this->assertSame(['Işıltı Kuaför'], $names('city=istanbul&district=kadikoy'));
        $this->assertSame([], $names('city=istanbul&district=besiktas'));
        $this->assertSame(['Ankara Berber'], $names('category=sakal'));
        $this->assertSame(['Ankara Berber'], $names('max_price=200'));
        $this->assertSame(['Işıltı Kuaför'], $names('min_price=1000'));
        $this->assertSame(['Işıltı Kuaför'], $names('service=boya'));
        $this->assertSame(['Ankara Berber', 'Işıltı Kuaför'], $names('sort=price_asc'));
        // Availability filter: Ankara Berber's only employee has no skills -> no free slot.
        $this->assertSame(['Işıltı Kuaför'], $names('date=2026-11-02'));
        $this->get('/salonlar/istanbul/kadikoy')->assertOk()->assertSee('Işıltı Kuaför')->assertDontSee('Ankara Berber');
        $this->get('/salonlar/olmayan-sehir')->assertNotFound();
    }

    public function test_hidden_or_suspended_tenants_are_not_listed(): void
    {
        $this->buildSalon();
        $this->tenant->forceFill(['public_listing_enabled' => false])->save();
        $this->getJson('/api/v1/salons')->assertJsonCount(0, 'data');
        $this->tenant->forceFill(['public_listing_enabled' => true, 'status' => 'suspended'])->save();
        $this->getJson('/api/v1/salons')->assertJsonCount(0, 'data');
        $this->get('/salon/'.$this->tenant->slug)->assertNotFound();
    }

    public function test_favorites(): void
    {
        $this->buildSalon();
        $u = User::factory()->create();
        $this->api($u)->postJson('/api/v1/me/favorites', ['slug' => $this->tenant->slug])->assertCreated();
        $this->api($u)->postJson('/api/v1/me/favorites', ['slug' => $this->tenant->slug])->assertCreated();
        $this->api($u)->getJson('/api/v1/me/favorites')->assertJsonCount(1, 'data');
        $this->api($u)->deleteJson('/api/v1/me/favorites/'.$this->tenant->slug)->assertOk();
        $this->api($u)->getJson('/api/v1/me/favorites')->assertJsonCount(0, 'data');
    }

    public function test_register_business_creates_trial_tenant(): void
    {
        $r = $this->postJson('/api/v1/auth/register', ['name' => 'Yeni Sahip', 'email' => 'Yeni@Example.com', 'phone' => '0532 999 88 77', 'password' => 'Guvenli12345',
            'password_confirmation' => 'Guvenli12345', 'kvkk_acknowledged' => true, 'business_name' => 'Yeni Salon', 'plan' => 'profesyonel'])->assertCreated();
        $this->assertSame('yeni-salon', $r->json('data.tenant.slug'));
        $this->assertDatabaseHas('users', ['email' => 'yeni@example.com', 'phone_e164' => '+905329998877']);
        $this->assertDatabaseHas('subscriptions', ['status' => 'trialing']);
        $token = $r->json('meta.token');
        $this->withToken($token)->withHeader('X-Tenant', 'yeni-salon')->getJson('/api/v1/tenant/dashboard')->assertOk();
    }

    public function test_phone_verification_links_guest_bookings_only_after_otp(): void
    {
        $this->buildSalon('automatic');
        $this->book($this->at('10:00'), phone: '+905551112233');
        $u = User::factory()->create(['phone_e164' => '+905551112233', 'phone_verified_at' => null]);
        $this->api($u)->getJson('/api/v1/me/appointments')->assertJsonCount(0, 'data');
        $this->api($u)->postJson('/api/v1/auth/verify-phone')->assertOk();
        $sms = app(SmsProviderInterface::class)->sent;
        preg_match('/(\d{6})/', end($sms)['message'], $m);
        $this->api($u)->postJson('/api/v1/auth/verify-phone', ['code' => '000000'])->assertStatus(422);
        $this->api($u)->postJson('/api/v1/auth/verify-phone', ['code' => $m[1]])->assertOk()->assertJsonPath('data.linked_bookings', 1);
        $this->api($u)->getJson('/api/v1/me/appointments')->assertJsonCount(1, 'data');
    }

    public function test_forgot_password_never_reveals_accounts(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        User::factory()->create(['email' => 'some@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'some@example.com'])->assertOk()->assertJsonPath('data.message', 'Bu e-posta kayıtlıysa şifre sıfırlama bağlantısı gönderildi.');
    }

    public function test_customer_data_export(): void
    {
        $this->buildSalon('automatic');
        $this->book($this->at('10:00'), phone: '+905551112233');
        $u = User::factory()->create(['phone_e164' => '+905551112233', 'phone_verified_at' => now()]);
        app(CustomerLinker::class)->linkVerifiedPhone($u);
        $this->actingAs($u)->get('/hesabim/veri-indir')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="verilerim.json"')->assertJsonCount(1, 'appointments');
    }
}
