<?php

namespace Tests\Feature\Web;

use App\Models\City;
use App\Models\District;
use App\Support\Money;
use App\Support\VisitorLocation;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class HomeRedesignTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function placeBranch(): array
    {
        $city = City::firstOrCreate(['slug' => 'istanbul'], ['name' => 'İstanbul', 'plate_code' => 34]);
        $district = District::firstOrCreate(['city_id' => $city->id, 'slug' => 'kadikoy'], ['name' => 'Kadıköy']);
        $this->branch->forceFill(['city_id' => $city->id, 'district_id' => $district->id])->save();

        return [$city, $district];
    }

    public function test_home_shows_category_tiles_popular_prices_and_location_prompt(): void
    {
        $this->buildSalon();
        $this->get('/')->assertOk()
            ->assertSee('Profesyonel Kuaför ve Güzellik Hizmetleri')
            ->assertSee(route('search', ['kategori' => 'sakal']), false)
            ->assertSeeText('Konumunuzu seçin')
            ->assertSeeText('Size Yakın Salonlar');
    }

    public function test_choosing_a_location_sets_cookie_and_lists_nearby_salons(): void
    {
        $this->buildSalon();
        [$city, $district] = $this->placeBranch();

        $this->post('/konum', ['il' => 'istanbul', 'ilce' => 'kadikoy', 'geri' => '/'])
            ->assertRedirect('/')->assertCookie(VisitorLocation::COOKIE, 'istanbul/kadikoy');

        $this->withCookie(VisitorLocation::COOKIE, 'istanbul/kadikoy')->get('/')->assertOk()
            ->assertSeeText('Kadıköy, İstanbul')
            ->assertSee(route('salon.show', $this->tenant->slug), false)
            ->assertDontSeeText('Konumunuzu seçin');

        $this->post('/konum', ['il' => '', 'geri' => '/'])->assertRedirect('/')->assertCookieExpired(VisitorLocation::COOKIE);
    }

    public function test_location_redirect_is_same_site_only(): void
    {
        $this->post('/konum', ['il' => 'yok', 'geri' => 'https://evil.test'])->assertRedirect('/');
        $this->post('/konum', ['il' => 'yok', 'geri' => '//evil.test/x'])->assertRedirect('/');
        $this->get('/konum?geri=https://evil.test')->assertOk()->assertSee('name="geri" value="/"', false);
    }

    public function test_free_text_search_matches_service_and_district_names(): void
    {
        $this->buildSalon();
        $this->placeBranch();
        $this->get('/salonlar?q=Boyama')->assertOk()->assertSee(route('salon.show', $this->tenant->slug), false);
        $this->get('/salonlar?q=Kadıköy')->assertOk()->assertSee(route('salon.show', $this->tenant->slug), false);
        $this->get('/salonlar?q=Bambaşka')->assertOk()->assertDontSee(route('salon.show', $this->tenant->slug), false);
    }

    public function test_salon_card_shows_category_tags_and_login_heart_for_guests(): void
    {
        $this->buildSalon();
        $this->get('/salonlar')->assertOk()->assertSeeText('Kuaför')->assertSee('Favorilere eklemek için giriş yapın', false);
    }

    public function test_money_short_format(): void
    {
        $this->assertSame('₺650', Money::parse('650.00')->short());
        $this->assertSame('₺1.800', Money::parse('1800')->short());
        $this->assertSame('₺99,90', Money::parse('99.90')->short());
    }
}
