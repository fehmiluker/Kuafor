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

    public function test_there_are_twenty_and_salons_dropped_from_the_list_are_removed(): void
    {
        $this->assertCount(20, BursaShowcaseSeeder::SALONS);
        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $old = Tenant::where('is_showcase', true)->firstOrFail();
        $old->forceFill(['slug' => 'eski-ornek-bursa'])->save();
        $real = Tenant::where('is_showcase', true)->skip(1)->firstOrFail();
        $real->forceFill(['is_showcase' => false])->save(); // a real salon is never touched

        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $this->assertSoftDeleted($old);
        $this->assertNotSoftDeleted($real);
    }

    public function test_masaj_tile_and_category_and_existing_salons_get_new_services(): void
    {
        $this->assertTrue(DB::table('service_categories')->whereNull('tenant_id')->where('slug', 'masaj')->exists());
        $this->get('/')->assertOk()->assertSee('Masaj')->assertSee('kategori=masaj', false)->assertDontSee('kategori=makyaj', false);

        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $this->get(route('search', ['city' => 'bursa', 'kategori' => 'masaj']))->assertOk()->assertSee('4 salon bulundu');

        // A salon created before "Masaj" was in the list gets the service on the next run (with its staff).
        $salon = Tenant::where('slug', 'ipek-yolu-guzellik-merkezi-bursa')->firstOrFail();
        DB::table('employee_services')->whereIn('service_id', DB::table('services')->where('tenant_id', $salon->id)->where('name', 'Aromaterapi Masajı')->pluck('id'))->delete();
        DB::table('branch_services')->whereIn('service_id', DB::table('services')->where('tenant_id', $salon->id)->where('name', 'Aromaterapi Masajı')->pluck('id'))->delete();
        DB::table('services')->where('tenant_id', $salon->id)->where('name', 'Aromaterapi Masajı')->delete();

        $this->artisan('kuafor:ornek-isletmeler')->assertSuccessful();
        $id = DB::table('services')->where('tenant_id', $salon->id)->where('name', 'Aromaterapi Masajı')->value('id');
        $this->assertNotNull($id);
        $this->assertSame(3, DB::table('employee_services')->where('service_id', $id)->count());
        $this->assertSame(1, DB::table('services')->where('tenant_id', $salon->id)->where('name', 'Aromaterapi Masajı')->count());
    }
}
