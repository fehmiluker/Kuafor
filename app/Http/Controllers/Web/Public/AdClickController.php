<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\Ads\AdService;
use Illuminate\Http\RedirectResponse;

/** Counts a banner click and sends the visitor to the advertiser. The target comes from the DB, never the query string (no open redirect). */
class AdClickController extends Controller
{
    public function __invoke(AdCampaign $ad, AdService $ads): RedirectResponse
    {
        abort_unless($ad->isRunning(), 404);
        $ads->recordClick($ad);

        return redirect()->away($ad->target_url);
    }
}
