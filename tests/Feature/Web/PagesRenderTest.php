<?php

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\User;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

/** Every screen renders for the right role (smoke test for Blade/Livewire wiring). */
class PagesRenderTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    public function test_public_pages_render(): void
    {
        $this->buildSalon();
        $this->get('/')->assertOk()->assertSee('Işıltı Kuaför');
        $this->get('/salonlar')->assertOk()->assertSee('salon bulundu');
        $this->get('/salon/'.$this->tenant->slug)->assertOk()->assertSee('Saç Kesimi')->assertSee('300,00 ₺')->assertSee('application/ld+json', false);
        $this->get('/salon/'.$this->tenant->slug.'/randevu')->assertOk()->assertSee('Randevu al');
        $this->get('/sitemap.xml')->assertOk()->assertSee($this->tenant->slug);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /panel');
    }

    public function test_tenant_panel_pages_render_for_owner(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $this->actingAs($this->owner($this->tenant));
        foreach (['/panel', '/panel/takvim', '/panel/randevular', '/panel/randevular/yeni', '/panel/randevular/'.$a->id, '/panel/subeler',
            '/panel/subeler/yeni', '/panel/subeler/'.$this->branch->id, '/panel/subeler/'.$this->branch->id.'/whatsapp', '/panel/personel',
            '/panel/personel/yeni', '/panel/personel/'.$this->ayse->id, '/panel/hizmetler', '/panel/hizmetler/yeni', '/panel/hizmetler/'.$this->cut->id,
            '/panel/musteriler', '/panel/musteriler/'.$a->customer_id, '/panel/degerlendirmeler', '/panel/raporlar', '/panel/ayarlar', '/panel/abonelik',
            '/panel/bildirimler'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/panel/randevular/'.$a->id)->assertSee('Başka saat öner')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_receptionist_sees_limited_navigation(): void
    {
        $this->buildSalon();
        $this->actingAs($this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]));
        $this->get('/panel')->assertOk()->assertDontSee('Abonelik')->assertDontSee('>Ayarlar<', false);
        $this->get('/panel/ayarlar')->assertForbidden();
        $this->get('/panel/hizmetler/yeni')->assertForbidden();
    }

    public function test_customer_pages_render(): void
    {
        $this->buildSalon();
        $this->actingAs(User::factory()->create());
        foreach (['/hesabim', '/hesabim/favoriler', '/hesabim/degerlendirmeler', '/hesabim/profil'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_pages_render_and_require_mfa(): void
    {
        $this->buildSalon();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/admin')->assertRedirect(route('two-factor.challenge'));
        $this->actingAs($admin)->withSession(['two_factor_passed' => true]);
        foreach (['/admin', '/admin/isletmeler', '/admin/isletmeler/'.$this->tenant->id, '/admin/paketler', '/admin/moderasyon', '/admin/denetim', '/admin/sistem'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin')->assertSee('MRR');
    }

    public function test_tenant_member_cannot_open_admin(): void
    {
        $this->buildSalon();
        $this->actingAs($this->owner($this->tenant))->get('/admin')->assertForbidden();
    }
}
