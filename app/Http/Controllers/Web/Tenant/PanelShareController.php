<?php

namespace App\Http\Controllers\Web\Tenant;

use App\Models\Branch;
use App\Services\Catalog\BookingLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Panel > Müşteri bağlantım: the salon's booking link, QR code (download / print) and link statistics. */
class PanelShareController extends PanelBaseController
{
    public function __construct(private readonly BookingLinkService $links) {}

    /** @return Collection<int, Branch> */
    private function branches(): Collection
    {
        return Branch::where('status', 'active')->orderBy('id')->get();
    }

    private function branchFrom(Request $request): ?Branch
    {
        $id = (int) $request->query('sube');

        return $id ? Branch::where('status', 'active')->findOrFail($id) : null; // tenant-scoped lookup
    }

    public function show(Request $request): View
    {
        $this->need($request, 'appointments.view');
        $tenant = $this->tenant();
        $branches = $this->branches();
        $url = $this->links->url($tenant);

        return view('tenant.share', [
            'tenant' => $tenant, 'branches' => $branches, 'url' => $url,
            'qr' => $this->links->qrSvg($this->links->url($tenant, 'qr'), 240),
            'whatsappText' => "{$tenant->name} için online randevunuzu buradan alabilirsiniz: ".$this->links->url($tenant, 'wa'),
            'branchUrls' => $branches->count() > 1 ? $branches->mapWithKeys(fn ($b) => [$b->id => $this->links->url($tenant, null, $b)]) : collect(),
            'stats' => $this->links->stats($tenant),
            'listed' => (bool) $tenant->public_listing_enabled,
        ]);
    }

    public function qr(Request $request): Response
    {
        $this->need($request, 'appointments.view');
        $tenant = $this->tenant();
        $branch = $this->branchFrom($request);
        $svg = $this->links->qrSvg($this->links->url($tenant, 'qr', $branch), 1024);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$tenant->slug.($branch ? '-'.$branch->slug : '').'-randevu-qr.svg"',
        ]);
    }

    public function print(Request $request): View
    {
        $this->need($request, 'appointments.view');
        $tenant = $this->tenant();
        $branch = $this->branchFrom($request);

        return view('tenant.share-print', [
            'tenant' => $tenant, 'branch' => $branch,
            'url' => $this->links->url($tenant, null, $branch),
            'qr' => $this->links->qrSvg($this->links->url($tenant, 'qr', $branch), 600),
        ]);
    }
}
