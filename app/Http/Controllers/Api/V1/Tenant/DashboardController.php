<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Enums\AppointmentStatus;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Services\Billing\EntitlementService;
use App\Services\Reports\ReportService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends TenantApiController
{
    public function dashboard(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $tenant = $this->tenant();
        [$from, $to] = LocalTime::dayBoundsUtc(CarbonImmutable::now($tenant->timezone)->toDateString(), $tenant->timezone);
        $q = $this->scope()->scopeAppointments(Appointment::query(), $request->user(), $tenant);

        return ApiResponse::ok([
            'today' => (clone $q)->where('starts_at_utc', '>=', $from)->where('starts_at_utc', '<', $to)->whereIn('status', AppointmentStatus::active())->count(),
            'pending' => (clone $q)->where('status', AppointmentStatus::Pending->value)->count(),
            'proposals_open' => (clone $q)->where('status', AppointmentStatus::AlternativeProposed->value)->count(),
            'upcoming_7d' => (clone $q)->where('starts_at_utc', '>=', now())->where('starts_at_utc', '<', now()->addDays(7))->where('status', AppointmentStatus::Confirmed->value)->count(),
            'usage' => $entitlements->usage($tenant),
            'over_limit' => $entitlements->overLimit($tenant),
            'rating' => ['average' => $tenant->rating_avg !== null ? (float) $tenant->rating_avg : null, 'count' => $tenant->rating_count],
        ]);
    }

    public function reports(Request $request, ReportService $reports): JsonResponse
    {
        abort_unless($request->user()->inTeam($this->tenant()->id, fn ($u) => $u->hasPermissionTo('reports.view')), 403);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = $this->tenant()->timezone;
        $from = CarbonImmutable::parse($data['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay()->utc();
        $to = CarbonImmutable::parse($data['to'] ?? now($tz)->toDateString(), $tz)->addDay()->startOfDay()->utc();

        return ApiResponse::ok($reports->summary($this->tenant(), $from, $to, $this->branchIds($request)));
    }
}
