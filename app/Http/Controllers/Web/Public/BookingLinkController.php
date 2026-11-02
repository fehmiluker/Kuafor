<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Services\Catalog\BookingLinkService;
use App\Services\Catalog\PublicCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** /r/{slug}: the salon's permanent customer link (shared, printed as QR) -> straight to booking. */
class BookingLinkController extends Controller
{
    public function __invoke(Request $request, string $slug, PublicCatalog $catalog, BookingLinkService $links): RedirectResponse
    {
        $tenant = $catalog->bookableTenant($slug);
        if (! $tenant) {
            // Showcase salons are viewable but not bookable; anything else is unknown.
            abort_unless($catalog->viewableTenant($slug), 404);

            return redirect()->route('salon.show', $slug);
        }
        $links->visit($tenant, BookingLinkService::source($request->query('k')));
        $branch = (int) $request->query('sube');

        return redirect()->route('salon.book', array_filter(['slug' => $tenant->slug, 'sube' => $branch ?: null]));
    }
}
