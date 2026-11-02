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
use App\Services\Catalog\RebookService;
use App\Services\Catalog\SalonCards;
use App\Services\Search\SalonSearchService;
use App\Support\Money;
use App\Support\VisitorLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicController extends Controller
{
    /** Category tiles on the home page: label => platform category slug + icon key. */
    public const HOME_CATEGORIES = [
        ['label' => 'Kuaför', 'slug' => 'sac-kesim', 'icon' => 'scissors', 'tone' => 'k1'],
        ['label' => 'Berber', 'slug' => 'sakal', 'icon' => 'razor', 'tone' => 'k2'],
        ['label' => 'Güzellik', 'slug' => 'cilt-bakimi', 'icon' => 'face', 'tone' => 'k3'],
        ['label' => 'Tırnak', 'slug' => 'manikur-pedikur', 'icon' => 'polish', 'tone' => 'k4'],
        ['label' => 'Masaj', 'slug' => 'masaj', 'icon' => 'lotus', 'tone' => 'k5'],
    ];

    /** "Popüler hizmetler" cards: platform category slug => [title, image]. */
    public const POPULAR_SERVICES = [
        'sac-kesim' => ['Saç Kesimi', 'images/home/sac-kesim.jpg'],
        'sac-boya' => ['Saç Boyama', 'images/home/sac-boya.jpg'],
        'sakal' => ['Erkek Bakımı', 'images/home/sakal.jpg'],
    ];

    public function home(Request $request, SalonSearchService $search, SalonCards $cards, RebookService $rebook): View
    {
        $withBranches = ['branches' => fn ($q) => $q->where('status', 'active')->with(['city', 'district'])];
        $featured = Tenant::query()->publiclyListed()->whereNotNull('rating_avg')->with($withBranches)
            ->orderBy('is_showcase')->orderByDesc('rating_avg')->orderByDesc('rating_count')->limit(4)->get();
        if ($featured->count() < 4) {
            $featured = $featured->concat(Tenant::query()->publiclyListed()->whereNotIn('id', $featured->pluck('id'))
                ->with($withBranches)->orderBy('is_showcase')->latest()->limit(4 - $featured->count())->get());
        }

        $location = VisitorLocation::fromRequest($request);
        $nearby = $location->isSet()
            ? $search->search(['city' => $location->city->slug, 'district' => $location->district?->slug, 'sort' => 'rating'], 4)->getCollection()
            : collect();
        $cards->prime($featured->concat($nearby));

        $prices = $cards->minPrices(array_keys(self::POPULAR_SERVICES));

        return view('public.home', [
            // Favourites + last visited salons ("son gittiğim salon" works for guests via cookie).
            'rebook' => $rebook->items($request->user(), $request, limit: 8),
            'featured' => $featured,
            'nearby' => $nearby,
            'location' => $location,
            'popular' => collect(self::POPULAR_SERVICES)->map(fn ($v, $slug) => [
                'slug' => $slug, 'title' => $v[0], 'image' => asset($v[1]),
                'price' => isset($prices[$slug]) ? Money::parse((string) $prices[$slug]) : null,
            ])->values(),
            'tiles' => self::HOME_CATEGORIES,
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
            'evime' => ['nullable', 'boolean'],
        ]);
        $cityModel = $city ? City::where('slug', $city)->first() : null;
        $districtModel = $cityModel && $district ? District::where('city_id', $cityModel->id)->where('slug', $district)->first() : null;
        abort_if(($city && ! $cityModel) || ($district && ! $districtModel), 404);

        $results = $search->search([
            'city' => $cityModel?->slug, 'district' => $districtModel?->slug, 'category' => $f['kategori'] ?? null,
            'service' => $f['hizmet'] ?? null, 'q' => $f['q'] ?? null, 'date' => $f['tarih'] ?? null, 'time' => $f['saat'] ?? null,
            'min_price' => $f['min'] ?? null, 'max_price' => $f['max'] ?? null, 'sort' => $f['sirala'] ?? 'rating',
            'home' => (bool) ($f['evime'] ?? false),
        ]);

        app(SalonCards::class)->prime($results->getCollection());

        return view('public.search', [
            'results' => $results, 'filters' => $f, 'city' => $cityModel, 'district' => $districtModel,
            'cities' => City::orderBy('name')->get(['id', 'name', 'slug']),
            'districts' => $cityModel ? $cityModel->districts : collect(),
            'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(),
        ]);
    }

    public function salon(Request $request, string $slug, PublicCatalog $catalog): View
    {
        $tenant = $catalog->viewableTenant($slug) ?? abort(404);
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
