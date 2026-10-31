<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Requests\Tenant\BookingSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Booking\BookingSettings;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends TenantApiController
{
    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()->inTeam($this->tenant()->id, fn ($u) => $u->hasPermissionTo('settings.manage')), 403);
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        $t = $this->tenant();

        return ApiResponse::ok([
            'name' => $t->name, 'slug' => $t->slug, 'description' => $t->description, 'timezone' => $t->timezone,
            'public_listing_enabled' => $t->public_listing_enabled, 'theme' => $t->settings['theme'] ?? null,
        ]);
    }

    public function update(Request $request, EntitlementService $entitlements, AuditLogger $audit): JsonResponse
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'public_listing_enabled' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'array'],
            'theme.primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $t = $this->tenant();
        if (isset($data['theme']) && ! $entitlements->hasFeature($t, 'custom_theme')) {
            return ApiResponse::fail(403, 'feature_unavailable', 'Özel tema paketinizde yer almıyor.');
        }
        $before = $t->only(['name', 'public_listing_enabled']);
        $t->forceFill(collect($data)->only(['name', 'description', 'public_listing_enabled'])->all());
        if (isset($data['theme'])) {
            $t->settings = array_merge($t->settings ?? [], ['theme' => $data['theme']]);
        }
        $t->save();
        $audit->log('tenant.settings_updated', $t, $before, $t->only(['name', 'public_listing_enabled']));

        return $this->show($request);
    }

    public function booking(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);

        return ApiResponse::ok(BookingSettings::for($this->tenant())->toArray());
    }

    public function updateBooking(BookingSettingsRequest $request, SettingsRepository $settings, AuditLogger $audit): JsonResponse
    {
        $this->authorizeSettings($request);
        $values = $request->validated();
        $settings->setTenant($this->tenant(), $values);
        $audit->log('tenant.booking_settings_updated', $this->tenant(), [], $values);

        return $this->booking($request);
    }
}
