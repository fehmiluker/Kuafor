<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\BursaDemoSeeder;
use Database\Seeders\GeoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BursaDemoSeederTest extends TestCase
{
    public function test_it_creates_listed_rated_bursa_salons_and_can_run_twice(): void
    {
        Storage::fake('public');
        $this->seed(GeoSeeder::class);
        $this->seed(BursaDemoSeeder::class);

        $tenants = Tenant::whereIn('name', array_column(BursaDemoSeeder::SALONS, 'name'))->get();
        $this->assertCount(count(BursaDemoSeeder::SALONS), $tenants);
        foreach ($tenants as $t) {
            $this->assertTrue($t->public_listing_enabled);
            $this->assertGreaterThan(0, $t->rating_count, $t->name);
            $this->assertNotNull($t->min_service_price, $t->name);
            Storage::disk('public')->assertExists($t->cover_path);
        }
        $bursaBranches = DB::table('branches')->join('cities', 'cities.id', '=', 'branches.city_id')
            ->where('cities.slug', 'bursa')->whereNotNull('branches.district_id')->count();
        $this->assertSame(array_sum(array_map(fn ($s) => count($s['branches']), BursaDemoSeeder::SALONS)), $bursaBranches);

        $this->seed(BursaDemoSeeder::class);
        $this->assertSame($tenants->count(), Tenant::whereIn('name', array_column(BursaDemoSeeder::SALONS, 'name'))->count());
    }
}
