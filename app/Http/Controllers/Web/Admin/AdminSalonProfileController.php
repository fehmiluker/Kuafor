<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Rules\Phone;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\SalonProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminSalonProfileController extends Controller
{
    private function need(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('tenants.manage'), 403);
    }

    public function edit(Request $request, Tenant $tenant): View
    {
        $this->need($request);

        return view('admin.salon-profile', [
            'tenant' => $tenant, 'branch' => $tenant->branches()->orderBy('id')->first(),
            'media' => $tenant->media()->get(), 'contact' => $tenant->settings['public_contact'] ?? [],
        ]);
    }

    public function update(Request $request, Tenant $tenant, SalonProfileService $profiles, AuditLogger $audit): RedirectResponse
    {
        $this->need($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', new Phone],
            ...SalonProfileService::rules($tenant),
        ]);
        DB::transaction(function () use ($tenant, $data, $profiles, $audit) {
            $tenant->forceFill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            $branch = $tenant->branches()->orderBy('id')->first();
            if ($branch) {
                $branch->forceFill(['address' => $data['address'] ?? null, 'phone' => $data['contact_phone'] ?? null])->save();
            }
            $audit->log('admin.salon_profile_updated', $tenant, [], ['name' => $tenant->name], tenantId: $tenant->id);
            $profiles->save($tenant, $data);
        });

        return back()->with('status', 'İşletme görselleri ve iletişim bilgileri kaydedildi.');
    }
}
