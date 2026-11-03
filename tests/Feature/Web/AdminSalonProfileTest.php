<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\Tenant;
use App\Models\TenantMedia;
use App\Models\User;
use App\Services\Catalog\SalonProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AdminSalonProfileTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function payload(): array
    {
        $city = City::firstOrCreate(['slug' => 'bursa'], ['name' => 'Bursa', 'plate_code' => 16]);

        return [
            'business_name' => 'Görselli Salon', 'owner_name' => 'Salon Sahibi', 'owner_email' => 'profile@example.test',
            'city_id' => $city->id, 'district_name' => 'Nilüfer', 'address' => 'Görükle Mahallesi',
            'plan' => 'profesyonel', 'category' => 'sac-kesim', 'services' => 'Kesim:30:500', 'employees' => 'Ayşe',
            'contact_phone' => '+905321234567', 'whatsapp_phone' => '+905331234567',
            'instagram_url' => 'https://www.instagram.com/salon/', 'facebook_url' => 'https://www.facebook.com/salon/',
        ];
    }

    public function test_creation_saves_banner_logo_gallery_and_public_contact_buttons(): void
    {
        Storage::fake('public');
        $this->asAdminWeb()->post('/admin/isletmeler', $this->payload() + [
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 800),
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'gallery' => [UploadedFile::fake()->image('inside.jpg', 600, 400), UploadedFile::fake()->image('inside2.jpg', 600, 400)],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $tenant = Tenant::where('name', 'Görselli Salon')->firstOrFail();
        Storage::disk('public')->assertExists([$tenant->cover_path, $tenant->logo_path]);
        $this->assertSame(2, $tenant->media()->count());
        foreach ($tenant->media as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
        $this->get(route('salon.show', $tenant->slug))->assertOk()
            ->assertSee(Storage::disk('public')->url($tenant->cover_path), false)
            ->assertSee('İşletmeden Fotoğraflar')->assertSee('Görükle Mahallesi')
            ->assertSee('tel:+905321234567', false)->assertSee('https://wa.me/905331234567', false)
            ->assertSee('https://www.instagram.com/salon/', false)->assertSee('https://www.facebook.com/salon/', false);
    }

    public function test_profile_edit_is_authorized_and_can_remove_a_gallery_photo(): void
    {
        Storage::fake('public');
        $this->buildSalon();
        $tenant = $this->tenant;
        $this->get('/admin/isletmeler/'.$tenant->id.'/profil')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/isletmeler/'.$tenant->id.'/profil')->assertForbidden();
        $path = 'tenants/'.$tenant->id.'/media/old.jpg';
        Storage::disk('public')->put($path, 'old');
        $photo = TenantMedia::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'path' => $path, 'mime' => 'image/jpeg', 'size' => 3,
            'alt_text' => 'Eski fotoğraf', 'visibility' => 'public', 'sort_order' => 0,
        ]);
        $this->asAdminWeb()->get('/admin/isletmeler/'.$tenant->id.'/profil')->assertOk()
            ->assertSee('name="gallery[]"', false)->assertSee('name="whatsapp_phone"', false);
        $this->put('/admin/isletmeler/'.$tenant->id.'/profil', [
            'name' => 'Yeni Profil', 'description' => 'Yeni açıklama', 'address' => 'Yeni Adres',
            'contact_phone' => '+905321234567', 'remove_media' => [$photo->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('tenant_media', ['id' => $photo->id]);
        $this->assertSame('Yeni Profil', $tenant->fresh()->name);
    }

    public function test_invalid_images_and_social_links_do_not_create_a_salon(): void
    {
        $this->asAdminWeb()->post('/admin/isletmeler', array_replace($this->payload(), [
            'cover' => UploadedFile::fake()->create('fake.jpg', 1, 'image/jpeg'),
            'instagram_url' => 'javascript:alert(1)', 'facebook_url' => 'https://facebook.com.evil.test/page',
        ]))->assertSessionHasErrors(['cover', 'instagram_url', 'facebook_url']);
        $this->assertDatabaseMissing('tenants', ['name' => 'Görselli Salon']);
    }

    public function test_profile_cannot_remove_another_tenants_photo(): void
    {
        $this->buildSalon();
        $first = $this->tenant;
        $this->buildSalon();
        $photo = TenantMedia::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'path' => 'other.jpg', 'mime' => 'image/jpeg', 'size' => 1,
            'visibility' => 'public', 'sort_order' => 0,
        ]);
        $this->asAdminWeb()->put('/admin/isletmeler/'.$first->id.'/profil', [
            'name' => $first->name, 'remove_media' => [$photo->id],
        ])->assertSessionHasErrors('remove_media.0');
        $this->assertDatabaseHas('tenant_media', ['id' => $photo->id]);
    }

    public function test_unsafe_stored_links_are_not_published_and_navigation_is_grouped(): void
    {
        $tenant = new Tenant;
        $tenant->settings = ['public_contact' => ['instagram_url' => 'javascript:alert(1)', 'facebook_url' => 'https://evil.test', 'whatsapp_phone' => 'bad']];
        $this->assertSame(['whatsapp' => null, 'instagram' => null, 'facebook' => null], SalonProfileService::links($tenant));
        $this->asAdminWeb()->get('/admin/icerik/banner')->assertOk()
            ->assertSeeText('İşletmeler')->assertSeeText('Abonelik ve Ödeme')
            ->assertSeeText('İçerik Yönetimi')->assertSeeText('Ayarlar')
            ->assertSee('aria-current="page">Bannerlar', false);
    }
}
