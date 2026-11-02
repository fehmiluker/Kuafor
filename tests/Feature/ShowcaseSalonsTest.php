<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\BursaShowcaseSeeder;
use Database\Seeders\GeoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowcaseSalonsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(GeoSeeder::class);
    }

    public function test_command_creates_all_showcase_salons_with_sample_reviews_and_is_idempotent(): void
    {
        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();

        $salons = Tenant::where('is_showcase', true)->get();
        $this->assertCount(count(BursaShowcaseSeeder::SALONS), $salons);
        foreach ($salons as $t) {
            $this->assertGreaterThanOrEqual(3, $t->rating_count, $t->name);
            $this->assertTrue($t->rating_avg >= 3 && $t->rating_avg <= 5, $t->name);
            $this->assertNotNull(DB::table('branches')->where('tenant_id', $t->id)->value('district_id'), $t->name);
        }
        $reviews = DB::table('reviews')->count();

        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $this->assertSame($salons->count(), Tenant::where('is_showcase', true)->count());
        $this->assertSame($reviews, DB::table('reviews')->count());
    }

    public function test_sample_reviews_are_labelled_and_not_exposed_as_structured_rating(): void
    {
        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $tenant = Tenant::where('is_showcase', true)->firstOrFail();

        $this->get(route('salon.show', $tenant->slug))->assertOk()
            ->assertSee('Örnek değerlendirmeler')->assertSee('Örnek yorum')
            ->assertDontSee('✓ Doğrulanmış')->assertDontSee('aggregateRating')->assertDontSee(route('salon.book', $tenant->slug));

        $top = Tenant::where('is_showcase', true)->orderByDesc('rating_avg')->orderByDesc('rating_count')->firstOrFail();
        $this->get(route('search', ['city' => 'bursa']))->assertOk()->assertSee('Örnek işletme')->assertSee($top->name)->assertDontSee('@else', false);
    }

    public function test_showcase_salons_without_reviews_from_an_earlier_run_get_reviews(): void
    {
        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $tenant = Tenant::where('is_showcase', true)->firstOrFail();
        DB::table('reviews')->where('tenant_id', $tenant->id)->delete();
        DB::table('tenants')->where('id', $tenant->id)->update(['rating_count' => 0, 'rating_avg' => null]);

        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $this->assertGreaterThan(0, $tenant->refresh()->rating_count);
    }
}
