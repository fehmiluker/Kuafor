<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;

/**
 * Picks the banner for a placement. Campaigns targeted at the visitor's city
 * win over nationwide ones; among equals the choice is random, weighted by
 * `weight`. Counting is a plain atomic increment (good enough for invoicing a
 * handful of campaigns; move to batched counters if traffic grows).
 */
final class AdService
{
    public function pick(string $placement, ?int $cityId = null): ?AdCampaign
    {
        if (! array_key_exists($placement, AdCampaign::PLACEMENTS)) {
            return null;
        }
        $candidates = AdCampaign::running()->where('placement', $placement)
            ->where(fn ($q) => $q->whereNull('city_id')->when($cityId, fn ($w) => $w->orWhere('city_id', $cityId)))
            ->get();
        if ($cityId && $candidates->contains('city_id', $cityId)) {
            $candidates = $candidates->where('city_id', $cityId)->values();
        }
        if ($candidates->isEmpty()) {
            return null;
        }

        $roll = random_int(1, (int) $candidates->sum('weight'));
        foreach ($candidates as $ad) {
            $roll -= $ad->weight;
            if ($roll <= 0) {
                return $ad;
            }
        }

        return $candidates->last();
    }

    public function recordImpression(AdCampaign $ad): void
    {
        AdCampaign::whereKey($ad->id)->increment('impressions');
    }

    public function recordClick(AdCampaign $ad): void
    {
        AdCampaign::whereKey($ad->id)->increment('clicks');
    }
}
