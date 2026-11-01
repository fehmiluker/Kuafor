<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\City;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\TenantCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin > Reklamlar: sponsor banners sold by the platform (e.g. salon supply companies). */
class AdminAdController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function need(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('platform.settings'), 403);
    }

    public function index(Request $request): View
    {
        $this->need($request);

        return view('admin.ads.index', ['ads' => AdCampaign::with('city')->latest('id')->get()]);
    }

    public function create(Request $request): View
    {
        $this->need($request);

        return view('admin.ads.form', ['ad' => null, 'cities' => City::orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(Request $request, AdCampaign $ad): View
    {
        $this->need($request);

        return view('admin.ads.form', ['ad' => $ad, 'cities' => City::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, TenantCatalogService $images): RedirectResponse
    {
        $this->need($request);
        $data = $this->validated($request, null);
        $ad = new AdCampaign;
        $this->fill($ad, $data, $request, $images);
        $this->audit->log('admin.ad_created', $ad, [], ['advertiser' => $ad->advertiser, 'placement' => $ad->placement]);

        return redirect()->route('admin.ads.index')->with('status', 'Reklam eklendi.');
    }

    public function update(Request $request, AdCampaign $ad, TenantCatalogService $images): RedirectResponse
    {
        $this->need($request);
        $data = $this->validated($request, $ad);
        $before = $ad->only(['advertiser', 'placement', 'target_url', 'active', 'starts_at', 'ends_at']);
        $this->fill($ad, $data, $request, $images);
        $this->audit->log('admin.ad_updated', $ad, $before, $ad->only(array_keys($before)));

        return redirect()->route('admin.ads.index')->with('status', 'Reklam güncellendi.');
    }

    public function destroy(Request $request, AdCampaign $ad): RedirectResponse
    {
        $this->need($request);
        $this->audit->log('admin.ad_deleted', $ad, ['advertiser' => $ad->advertiser, 'impressions' => $ad->impressions, 'clicks' => $ad->clicks]);
        Storage::disk('public')->delete(array_filter([$ad->image_path, $ad->image_mobile_path]));
        $ad->delete();

        return redirect()->route('admin.ads.index')->with('status', 'Reklam silindi.');
    }

    /** The form uses Istanbul wall time (datetime-local); the DB stores UTC. */
    private function localToUtc(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, (string) config('app.display_timezone'))->utc() : null;
    }

    private function validated(Request $request, ?AdCampaign $ad): array
    {
        $image = ['file', 'image', 'mimes:jpeg,png,webp', 'max:2048'];

        return $request->validate([
            'advertiser' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'placement' => ['required', Rule::in(array_keys(AdCampaign::PLACEMENTS))],
            'target_url' => ['required', 'url:https,http', 'max:500'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($request->filled('starts_at'), 'after:starts_at')],
            'weight' => ['required', 'integer', 'between:1,100'],
            'active' => ['nullable', 'boolean'],
            // Wide banner (e.g. 1200x200); mobile variant optional (e.g. 640x200).
            'image' => [$ad ? 'nullable' : 'required', ...$image, 'dimensions:min_width=600,min_height=80,max_width=4000,max_height=1500'],
            'image_mobile' => ['nullable', ...$image, 'dimensions:min_width=300,min_height=80,max_width=2000,max_height=1500'],
        ]);
    }

    private function fill(AdCampaign $ad, array $data, Request $request, TenantCatalogService $images): void
    {
        $ad->fill([
            'advertiser' => $data['advertiser'], 'title' => $data['title'], 'placement' => $data['placement'],
            'target_url' => $data['target_url'], 'city_id' => $data['city_id'] ?? null,
            'starts_at' => $this->localToUtc($data['starts_at'] ?? null), 'ends_at' => $this->localToUtc($data['ends_at'] ?? null),
            'weight' => $data['weight'], 'active' => (bool) ($data['active'] ?? false),
        ]);
        // Images are re-encoded (TenantCatalogService::storeImage): no SVG/scripts from uploads.
        foreach (['image' => 'image_path', 'image_mobile' => 'image_mobile_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                $old = $ad->{$column};
                $ad->{$column} = $images->storeImage($request->file($field), 'ads');
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            }
        }
        if ($request->boolean('remove_mobile') && $ad->image_mobile_path) {
            Storage::disk('public')->delete($ad->image_mobile_path);
            $ad->image_mobile_path = null;
        }
        $ad->save();
    }
}
