<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\AdCampaign;
use App\Models\City;
use App\Services\Ads\AdService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class AdsTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function ad(array $attrs = []): AdCampaign
    {
        return AdCampaign::create($attrs + [
            'advertiser' => 'Malzeme Firması', 'title' => 'Salonlara indirim', 'placement' => 'panel_dashboard',
            'image_path' => 'ads/x.jpg', 'target_url' => 'https://example.com/kampanya', 'active' => true, 'weight' => 1,
        ]);
    }

    public function test_only_running_campaigns_for_the_placement_are_picked(): void
    {
        $ads = app(AdService::class);
        $this->ad(['active' => false]);
        $this->ad(['starts_at' => now()->addDay()]);
        $this->ad(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDays(2)]);
        $this->ad(['placement' => 'home']);
        $this->assertNull($ads->pick('panel_dashboard'));
        $this->assertNull($ads->pick('unknown'));

        $live = $this->ad(['ends_at' => now()->addDay()]);
        $this->assertTrue($ads->pick('panel_dashboard')->is($live));
    }

    public function test_city_targeted_campaign_wins_in_its_city_only(): void
    {
        $ist = City::firstOrCreate(['slug' => 'istanbul'], ['name' => 'İstanbul', 'plate_code' => 34]);
        $ank = City::firstOrCreate(['slug' => 'ankara'], ['name' => 'Ankara', 'plate_code' => 6]);
        $national = $this->ad(['placement' => 'search']);
        $local = $this->ad(['placement' => 'search', 'city_id' => $ist->id]);
        $ads = app(AdService::class);

        $this->assertTrue($ads->pick('search', $ist->id)->is($local));
        $this->assertTrue($ads->pick('search', $ank->id)->is($national));
        $this->assertTrue($ads->pick('search')->is($national));
    }

    public function test_panel_dashboard_shows_labelled_banner_and_counts_impression(): void
    {
        $this->buildSalon();
        $ad = $this->ad();
        $this->actingAs($this->owner($this->tenant));

        $this->get('/panel')->assertOk()
            ->assertSee(route('ads.click', $ad), false)
            ->assertSee('rel="sponsored noopener"', false)
            ->assertSeeText('Reklam')
            ->assertSee('Salonlara indirim', false);
        $this->assertSame(1, $ad->fresh()->impressions);
    }

    public function test_no_banner_markup_without_campaigns(): void
    {
        $this->buildSalon();
        $this->actingAs($this->owner($this->tenant));
        $this->get('/panel')->assertOk()->assertDontSee('aria-label="Reklam"', false);
        $this->get('/')->assertOk()->assertDontSee('aria-label="Reklam"', false);
    }

    public function test_click_is_counted_and_redirects_to_stored_url_only(): void
    {
        $ad = $this->ad();
        $this->get('/reklam/'.$ad->id.'?to=https://evil.test')->assertRedirect('https://example.com/kampanya');
        $this->assertSame(1, $ad->fresh()->clicks);

        $ad->update(['active' => false]);
        $this->get('/reklam/'.$ad->id)->assertNotFound();
        $this->assertSame(1, $ad->fresh()->clicks);
    }

    public function test_only_platform_admins_manage_ads(): void
    {
        $this->buildSalon();
        $this->actingAs($this->owner($this->tenant))->get('/admin/reklamlar')->assertForbidden();
        $this->actingAs($this->member($this->tenant, TenantRole::Receptionist, [$this->branch->id]))->get('/admin/reklamlar')->assertForbidden();
    }

    public function test_admin_creates_banner_with_reencoded_image_and_istanbul_dates(): void
    {
        Storage::fake('public');
        $this->asAdminWeb();

        $this->get('/admin/reklamlar')->assertOk()->assertSeeText('Henüz reklam yok');
        $this->post('/admin/reklamlar', [
            'advertiser' => 'Malzeme A.Ş.', 'title' => 'Profesyonel makaslar', 'placement' => 'panel_dashboard',
            'target_url' => 'https://example.com/makas', 'weight' => 2, 'active' => '1',
            'starts_at' => '2026-11-10T09:00', 'ends_at' => '2026-12-10T09:00',
            'image' => UploadedFile::fake()->image('banner.png', 1200, 200),
        ])->assertRedirect('/admin/reklamlar');

        $ad = AdCampaign::firstOrFail();
        $this->assertStringEndsWith('.jpg', $ad->image_path, 'uploads are re-encoded to JPEG');
        Storage::disk('public')->assertExists($ad->image_path);
        $this->assertSame('2026-11-10 06:00:00', $ad->starts_at->utc()->format('Y-m-d H:i:s'), 'Istanbul 09:00 = 06:00 UTC');

        $this->post('/admin/reklamlar', [
            'advertiser' => 'X', 'title' => 'Y', 'placement' => 'panel_dashboard', 'target_url' => 'javascript:alert(1)', 'weight' => 1,
            'image' => UploadedFile::fake()->image('b.png', 1200, 200),
        ])->assertSessionHasErrors('target_url');
        $this->post('/admin/reklamlar', [
            'advertiser' => 'X', 'title' => 'Y', 'placement' => 'panel_dashboard', 'target_url' => 'https://example.com', 'weight' => 1,
            'image' => UploadedFile::fake()->create('b.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('image');
        $this->assertSame(1, AdCampaign::count());

        $this->delete('/admin/reklamlar/'.$ad->id)->assertRedirect('/admin/reklamlar');
        Storage::disk('public')->assertMissing($ad->image_path);
    }
}
