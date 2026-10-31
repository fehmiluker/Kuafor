<?php

namespace App\Http\Controllers\Web\Public;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\City;
use App\Models\CustomerFavorite;
use App\Models\District;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\TenantMedia;
use App\Services\Catalog\PublicCatalog;
use App\Services\Search\SalonSearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicController extends Controller
{
    public function home(): View
    {
        $featured = Tenant::query()->publiclyListed()->whereNotNull('rating_avg')
            ->with(['branches' => fn ($q) => $q->where('status', 'active')->with(['city', 'district'])])
            ->orderByDesc('rating_avg')->orderByDesc('rating_count')->limit(6)->get();
        if ($featured->isEmpty()) {
            $featured = Tenant::query()->publiclyListed()->with(['branches' => fn ($q) => $q->where('status', 'active')->with(['city', 'district'])])->latest()->limit(6)->get();
        }

        return view('public.home', [
            'featured' => $featured,
            'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(),
            'cities' => City::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    /** SEO URLs: /salonlar/istanbul/kadikoy?kategori=sac-kesim */
    public function search(Request $request, SalonSearchService $search, ?string $city = null, ?string $district = null): View
    {
        $f = $request->validate([
            'kategori' => ['nullable', 'string', 'max:100'], 'hizmet' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100'],
            'tarih' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'saat' => ['nullable', 'date_format:H:i'],
            'min' => ['nullable', 'numeric', 'min:0'], 'max' => ['nullable', 'numeric', 'min:0'],
            'sirala' => ['nullable', 'in:'.implode(',', SalonSearchService::SORTS)],
        ]);
        $cityModel = $city ? City::where('slug', $city)->first() : null;
        $districtModel = $cityModel && $district ? District::where('city_id', $cityModel->id)->where('slug', $district)->first() : null;
        abort_if(($city && ! $cityModel) || ($district && ! $districtModel), 404);

        $results = $search->search([
            'city' => $cityModel?->slug, 'district' => $districtModel?->slug, 'category' => $f['kategori'] ?? null,
            'service' => $f['hizmet'] ?? null, 'q' => $f['q'] ?? null, 'date' => $f['tarih'] ?? null, 'time' => $f['saat'] ?? null,
            'min_price' => $f['min'] ?? null, 'max_price' => $f['max'] ?? null, 'sort' => $f['sirala'] ?? 'rating',
        ]);

        return view('public.search', [
            'results' => $results, 'filters' => $f, 'city' => $cityModel, 'district' => $districtModel,
            'cities' => City::orderBy('name')->get(['id', 'name', 'slug']),
            'districts' => $cityModel ? $cityModel->districts : collect(),
            'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(),
        ]);
    }

    public function salon(Request $request, string $slug, PublicCatalog $catalog): View
    {
        $tenant = $catalog->bookableTenant($slug) ?? abort(404);
        $branches = $catalog->branches($tenant);
        $servicesByBranch = $branches->mapWithKeys(fn ($b) => [$b->id => $catalog->services($b)]);
        $employees = $branches->flatMap(fn ($b) => $catalog->employees($b))->unique('id')->values();
        $reviews = Review::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', ReviewStatus::Published->value)
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes(), 'reply' => fn ($q) => $q->withoutGlobalScopes(), 'employee' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest()->paginate(8);
        $media = TenantMedia::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('visibility', 'public')->orderBy('sort_order')->limit(12)->get();
        $isFavorite = $request->user() ? CustomerFavorite::where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->exists() : false;

        return view('public.salon', compact('tenant', 'branches', 'servicesByBranch', 'employees', 'reviews', 'media', 'isFavorite'));
    }

    public function sitemap(): Response
    {
        $tenants = Tenant::query()->publiclyListed()->get(['slug', 'updated_at']);
        $cities = City::whereIn('id', Branch::withoutGlobalScopes()->whereNotNull('city_id')->select('city_id'))->get(['slug']);

        return response()->view('public.sitemap', compact('tenants', 'cities'))->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Disallow: /panel', 'Disallow: /admin', 'Disallow: /hesabim', 'Disallow: /randevu/', 'Disallow: /teklif/', 'Disallow: /api/', 'Sitemap: '.route('sitemap')];

        return response(implode("\n", $lines)."\n", 200)->header('Content-Type', 'text/plain');
    }
}
