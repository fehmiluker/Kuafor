<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\HomeContent;
use App\Models\PlatformSetting;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\HostedCheckoutGateway;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AdminContentManagementTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    public function test_management_pages_require_platform_role_and_mfa(): void
    {
        foreach (['/admin/icerik/banner', '/admin/icerik/popular', '/admin/kategoriler', '/admin/odeme-ayarlari', '/admin/isletmeler/yeni'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create())->get('/admin/kategoriler')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/admin/kategoriler')->assertRedirect(route('two-factor.challenge'));
        $this->withSession(['two_factor_passed' => true]);
        foreach (['/admin/icerik/banner', '/admin/icerik/popular', '/admin/kategoriler', '/admin/odeme-ayarlari', '/admin/isletmeler/yeni'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_banner_images_publish_update_and_delete(): void
    {
        Storage::fake('public');
        $this->asAdminWeb()->post('/admin/icerik/banner', [
            'title' => 'Yeni Kampanya', 'description' => 'Kampanya metni', 'button_text' => 'İncele', 'target_url' => '/salonlar',
            'sort_order' => 0, 'active' => 1, 'image' => UploadedFile::fake()->image('banner.jpg', 1200, 500),
            'image_mobile' => UploadedFile::fake()->image('mobile.jpg', 600, 500),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $item = HomeContent::where('title', 'Yeni Kampanya')->firstOrFail();
        Storage::disk('public')->assertExists([$item->image_path, $item->image_mobile_path]);
        $this->get('/')->assertOk()->assertSee('Yeni Kampanya')->assertSee($item->imageUrl(true), false);
        $this->put('/admin/icerik/banner/'.$item->id, ['title' => 'Gizli Kampanya', 'sort_order' => 0, 'active' => 0])->assertSessionHasNoErrors();
        $this->get('/')->assertDontSee('Gizli Kampanya');
        $this->delete('/admin/icerik/popular/'.$item->id)->assertNotFound();
        $this->delete('/admin/icerik/banner/'.$item->id)->assertRedirect();
        $this->assertDatabaseMissing('home_contents', ['id' => $item->id]);
        Storage::disk('public')->assertMissing([$item->image_path, $item->image_mobile_path]);
    }

    public function test_unsafe_targets_are_rejected(): void
    {
        $this->asAdminWeb();
        $item = HomeContent::where('kind', 'banner')->firstOrFail();
        foreach (['javascript:alert(1)', '//evil.test', '/'.chr(92).'evil.test'] as $url) {
            $this->put('/admin/icerik/banner/'.$item->id, ['title' => 'Unsafe', 'sort_order' => 0, 'active' => 1, 'target_url' => $url])->assertSessionHasErrors('target_url');
        }
    }

    public function test_popular_services_are_ordered_and_not_limited_to_three(): void
    {
        for ($i = 0; $i < 6; $i++) {
            HomeContent::create(['kind' => 'popular', 'title' => 'Hizmet '.$i, 'asset_path' => 'images/home/sac-kesim.jpg', 'sort_order' => 20 - $i, 'active' => true]);
        }
        $this->get('/')->assertOk()->assertSeeInOrder(['Hizmet 5', 'Hizmet 4', 'Hizmet 3', 'Hizmet 2', 'Hizmet 1', 'Hizmet 0'])->assertSee('data-carousel-track', false);
    }

    public function test_category_is_published_in_home_search_and_onboarding_and_can_be_hidden(): void
    {
        $this->asAdminWeb()->post('/admin/kategoriler', [
            'name' => 'Yeni Bakım', 'slug' => 'yeni-bakim', 'home_label' => 'Yeni Kategori', 'icon' => 'star', 'tone' => 'k3',
            'status' => 'active', 'show_on_home' => 1, 'sort_order' => 0,
        ])->assertSessionHasNoErrors();
        $c = ServiceCategory::where('slug', 'yeni-bakim')->firstOrFail();
        $this->get('/')->assertSee('Yeni Kategori');
        $this->get('/salonlar')->assertSee('Yeni Bakım');
        $this->get('/admin/isletmeler/yeni')->assertSee('Yeni Bakım');
        $this->put('/admin/kategoriler/'.$c->id, ['name' => $c->name, 'slug' => $c->slug, 'icon' => 'star', 'tone' => 'k3', 'status' => 'inactive', 'sort_order' => 0])->assertSessionHasNoErrors();
        $this->get('/')->assertDontSee('Yeni Kategori');
        $this->get('/salonlar')->assertDontSee('Yeni Bakım');
    }

    public function test_category_with_services_requires_replacement_and_moves_services(): void
    {
        $this->buildSalon();
        $old = ServiceCategory::whereNull('tenant_id')->where('slug', 'sac-kesim')->firstOrFail();
        $new = ServiceCategory::whereNull('tenant_id')->where('slug', 'sac-boya')->firstOrFail();
        DB::table('services')->where('tenant_id', $this->tenant->id)->update(['category_id' => $old->id]);
        $this->asAdminWeb()->delete('/admin/kategoriler/'.$old->id)->assertSessionHasErrors('replacement_id');
        $this->assertDatabaseHas('service_categories', ['id' => $old->id]);
        $this->delete('/admin/kategoriler/'.$old->id, ['replacement_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('service_categories', ['id' => $old->id]);
        $this->assertDatabaseMissing('services', ['category_id' => $old->id]);
    }

    public function test_payment_settings_encrypt_secrets_do_not_flash_them_and_use_runtime_gateway(): void
    {
        $this->asAdminWeb()->put('/admin/odeme-ayarlari', ['provider' => 'paytr', 'merchant_key' => 'secret-key'])->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('merchant_key'));
        $this->put('/admin/odeme-ayarlari', ['provider' => 'paytr', 'merchant_id' => '123456', 'merchant_key' => 'secret-key', 'merchant_salt' => 'secret-salt', 'test_mode' => 1])->assertSessionHasNoErrors();
        $row = PlatformSetting::where('key', 'paytr_merchant_key')->firstOrFail();
        $this->assertTrue((bool) $row->is_encrypted);
        $this->assertNotSame('secret-key', $row->value);
        $this->assertSame('secret-key', app(SettingsRepository::class)->platform()['paytr_merchant_key']);
        $this->get('/admin/odeme-ayarlari')->assertOk()->assertDontSee('secret-key')->assertDontSee('secret-salt');
        app()->forgetScopedInstances();
        $gateway = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(HostedCheckoutGateway::class, $gateway);
        $this->assertSame('paytr', $gateway->name());
        $this->assertStringNotContainsString('secret-key', (string) DB::table('audit_logs')->pluck('after')->implode(''));
    }

    public function test_admin_creates_complete_salon_without_resetting_existing_data(): void
    {
        $city = City::firstOrCreate(['slug' => 'bursa'], ['name' => 'Bursa', 'plate_code' => 16]);
        $this->asAdminWeb()->post('/admin/isletmeler', [
            'business_name' => 'Yeni Salon', 'owner_name' => 'Salon Sahibi', 'owner_email' => 'owner@example.test', 'owner_phone' => null,
            'city_id' => $city->id, 'district_name' => 'Nilüfer', 'address' => 'Adres', 'plan' => 'profesyonel', 'category' => 'sac-kesim',
            'services' => 'Saç Kesimi:30:500', 'employees' => 'Ayşe | Mehmet',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $tenant = Tenant::where('name', 'Yeni Salon')->firstOrFail();
        $this->assertSame(2, DB::table('employees')->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'city_id' => $city->id]);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id]);
    }
}
