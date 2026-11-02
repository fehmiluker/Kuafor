<?php

namespace App\Services\Catalog;

use App\Models\CustomerFavorite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data for the public salon cards (category tags, favourite hearts), loaded
 * once per page for all listed salons instead of one query per card.
 * Registered as a scoped (per-request) singleton.
 */
final class SalonCards
{
    /** Short tag per platform category slug (cards have room for ~3 short tags). */
    private const SHORT = [
        'sac-kesim' => 'Kuaför', 'sac-boya' => 'Boya', 'fon-sekillendirme' => 'Fön', 'sakal' => 'Berber',
        'manikur-pedikur' => 'Manikür', 'cilt-bakimi' => 'Cilt bakımı', 'makyaj' => 'Makyaj', 'masaj' => 'Masaj',
        'kas-kirpik' => 'Kaş & Kirpik', 'epilasyon' => 'Epilasyon', 'gelin' => 'Gelin',
    ];

    /** @var array<int, list<string>> */
    private array $tags = [];

    /** @var array<int, true>|null */
    private ?array $favorites = null;

    /** @param  iterable<Tenant>  $tenants */
    public function prime(iterable $tenants): self
    {
        $ids = collect($tenants)->pluck('id')->diff(array_keys($this->tags))->values();
        if ($ids->isEmpty()) {
            return $this;
        }
        // Query builder on purpose: services are tenant-scoped models and this is a cross-tenant public listing.
        $rows = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->whereIn('services.tenant_id', $ids)->where('services.active', true)->whereNull('services.deleted_at')
            ->whereNull('service_categories.tenant_id')
            ->select('services.tenant_id', 'service_categories.slug', 'service_categories.name', 'service_categories.sort_order')
            ->distinct()->orderBy('service_categories.sort_order')->get();
        foreach ($ids as $id) {
            $this->tags[$id] = [];
        }
        foreach ($rows as $r) {
            $label = self::SHORT[$r->slug] ?? $r->name;
            if (! in_array($label, $this->tags[$r->tenant_id], true) && count($this->tags[$r->tenant_id]) < 3) {
                $this->tags[$r->tenant_id][] = $label;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function tags(Tenant $tenant): array
    {
        if (! array_key_exists($tenant->id, $this->tags)) {
            $this->prime([$tenant]);
        }

        return $this->tags[$tenant->id];
    }

    public function isFavorite(Tenant $tenant, ?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $this->favorites ??= CustomerFavorite::where('user_id', $user->id)->pluck('tenant_id')->flip()->map(fn () => true)->all();

        return isset($this->favorites[$tenant->id]);
    }

    /**
     * Lowest public price per category slug among listed salons ("Saç Kesimi ₺650'den").
     *
     * @param  list<string>  $slugs
     * @return Collection<string, string> slug => decimal
     */
    public function minPrices(array $slugs): Collection
    {
        $listed = Tenant::query()->publiclyListed()->select('tenants.id');

        return DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->whereIn('services.tenant_id', $listed)->where('services.active', true)->whereNull('services.deleted_at')
            ->whereIn('service_categories.slug', $slugs)->whereNull('service_categories.tenant_id')
            ->groupBy('service_categories.slug')
            ->selectRaw('service_categories.slug as slug, MIN(services.base_price) as min_price')
            ->pluck('min_price', 'slug');
    }
}
