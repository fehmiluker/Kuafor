<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Support\VisitorLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/** "Kadıköy, İstanbul ▾": choose the area used for "Size yakın salonlar". */
class LocationController extends Controller
{
    public function edit(Request $request): View
    {
        $current = VisitorLocation::fromRequest($request);
        $city = $request->filled('il') ? City::where('slug', $request->string('il'))->first() : $current->city;

        return view('public.location', [
            'cities' => City::orderBy('name')->get(['id', 'name', 'slug']),
            'city' => $city,
            'districts' => $city ? $city->districts()->orderBy('name')->get(['id', 'name', 'slug']) : collect(),
            'district' => $city?->is($current->city) ? $current->district : null,
            'back' => $this->safeBack($request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'il' => ['nullable', 'string', 'max:80'], 'ilce' => ['nullable', 'string', 'max:80'], 'geri' => ['nullable', 'string', 'max:300'],
        ]);
        $location = VisitorLocation::fromSlugs($data['il'] ?? null, $data['ilce'] ?? null);
        $cookie = $location->isSet()
            ? Cookie::make(VisitorLocation::COOKIE, $location->cookieValue(), 60 * 24 * 365, sameSite: 'lax')
            : Cookie::forget(VisitorLocation::COOKIE);

        // No flash message: the header pill already shows the new location.
        return redirect($this->safeBack($request))->withCookie($cookie);
    }

    /** Only same-site relative paths (no open redirect). */
    private function safeBack(Request $request): string
    {
        $back = (string) $request->input('geri', '/');

        return str_starts_with($back, '/') && ! str_starts_with($back, '//') && ! str_contains($back, '\\') ? $back : '/';
    }
}
