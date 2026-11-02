<?php

namespace App\Support;

use App\Models\City;
use App\Models\District;
use Illuminate\Http\Request;

/**
 * The visitor's chosen area ("Kadıköy, İstanbul"), kept in a cookie as
 * "city-slug/district-slug". No geolocation and no personal data: it only
 * narrows "Size yakın salonlar" and pre-fills search.
 */
final class VisitorLocation
{
    public const COOKIE = 'konum';

    public function __construct(public readonly ?City $city = null, public readonly ?District $district = null) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromSlugs(...array_pad(explode('/', (string) $request->cookie(self::COOKIE, ''), 2), 2, null));
    }

    public static function fromSlugs(?string $citySlug, ?string $districtSlug = null): self
    {
        $city = $citySlug ? City::where('slug', $citySlug)->first() : null;
        $district = $city && $districtSlug ? District::where('city_id', $city->id)->where('slug', $districtSlug)->first() : null;

        return new self($city, $district);
    }

    public function isSet(): bool
    {
        return $this->city !== null;
    }

    public function label(): string
    {
        return $this->city ? collect([$this->district?->name, $this->city->name])->filter()->implode(', ') : 'Konum seçin';
    }

    public function cookieValue(): string
    {
        return $this->city ? $this->city->slug.($this->district ? '/'.$this->district->slug : '') : '';
    }

    /** Search URL for this area (SEO path /salonlar/{il}/{ilce}). */
    public function searchUrl(array $query = []): string
    {
        $params = array_filter([$this->city?->slug, $this->district?->slug]);

        return route('search', $params + $query);
    }
}
