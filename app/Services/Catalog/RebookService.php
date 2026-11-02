<?php

namespace App\Services\Catalog;

use App\Enums\AppointmentStatus;
use App\Models\Branch;
use App\Models\CustomerFavorite;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\CustomerLinker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * "Tekrar randevu al": remembers the customer's last booking per salon and builds
 * a booking-wizard URL with the same branch, services and employee pre-selected.
 * Logged-in customers: from their appointment history. Guests: from the
 * functional "son_salon" cookie set after an online booking (no account needed).
 */
final class RebookService
{
    public const COOKIE = 'son_salon';

    private const COOKIE_DAYS = 365;

    public function __construct(private readonly CustomerLinker $linker) {}

    public function rememberCookie(int $tenantId, int $branchId, array $serviceIds, ?int $employeeId): SymfonyCookie
    {
        $value = json_encode(['t' => $tenantId, 'b' => $branchId, 's' => array_values(array_map('intval', $serviceIds)), 'e' => $employeeId, 'at' => now()->timestamp]);

        return Cookie::make(self::COOKIE, (string) $value, 60 * 24 * self::COOKIE_DAYS, sameSite: 'lax');
    }

    /**
     * Last visited salons, newest first: tenant_id => [branch_id, service_ids, employee_id, last_at (CarbonImmutable)].
     *
     * @return Collection<int, array{branch_id:int, service_ids:list<int>, employee_id:?int, last_at:CarbonImmutable}>
     */
    public function history(?User $user, ?Request $request = null): Collection
    {
        $out = collect();
        if ($user && ($customerIds = $this->linker->customerIds($user))) {
            $latest = DB::table('appointments')->whereIn('customer_id', $customerIds)
                ->whereIn('status', [AppointmentStatus::Completed->value, AppointmentStatus::Confirmed->value])
                ->groupBy('tenant_id')->selectRaw('MAX(id) id');
            $rows = DB::table('appointments')->whereIn('id', $latest)->orderByDesc('starts_at_utc')->get(['id', 'tenant_id', 'branch_id', 'employee_id', 'starts_at_utc']);
            $services = DB::table('appointment_services')->whereIn('appointment_id', $rows->pluck('id'))->whereNotNull('service_id')
                ->orderBy('sort_order')->get(['appointment_id', 'service_id'])->groupBy('appointment_id');
            foreach ($rows as $r) {
                $out[$r->tenant_id] = [
                    'branch_id' => (int) $r->branch_id, 'employee_id' => (int) $r->employee_id,
                    'service_ids' => ($services[$r->id] ?? collect())->pluck('service_id')->map(fn ($id) => (int) $id)->all(),
                    'last_at' => CarbonImmutable::parse($r->starts_at_utc, 'UTC'),
                ];
            }
        }
        if ($request && ($cookie = $this->fromCookie($request)) && ! $out->has($cookie['tenant_id'])) {
            $out = collect([$cookie['tenant_id'] => array_diff_key($cookie, ['tenant_id' => 1])])->union($out);
        }

        return $out;
    }

    /** @return array{tenant_id:int, branch_id:int, service_ids:list<int>, employee_id:?int, last_at:CarbonImmutable}|null */
    public function fromCookie(Request $request): ?array
    {
        $data = json_decode((string) $request->cookie(self::COOKIE, ''), true);
        if (! is_array($data) || ! isset($data['t'], $data['b']) || ! is_int($data['t']) || ! is_int($data['b'])) {
            return null;
        }

        return [
            'tenant_id' => $data['t'], 'branch_id' => $data['b'],
            'service_ids' => array_values(array_filter(array_map('intval', (array) ($data['s'] ?? [])))),
            'employee_id' => isset($data['e']) && is_int($data['e']) ? $data['e'] : null,
            'last_at' => CarbonImmutable::createFromTimestamp((int) ($data['at'] ?? time()), 'UTC'),
        ];
    }

    /**
     * Cards for "Tekrar randevu al" / Favoriler: visited salons (newest first) and favourites,
     * only salons that can still take bookings.
     *
     * @return Collection<int, array{tenant: Tenant, url: string, last_at: ?CarbonImmutable, favorite: bool, branch: ?Branch}>
     */
    public function items(?User $user, Request $request, bool $favorites = true, bool $history = true, int $limit = 50): Collection
    {
        $past = $history ? $this->history($user, $request) : collect();
        $favIds = $user && $favorites ? CustomerFavorite::where('user_id', $user->id)->latest('id')->pluck('tenant_id')->all() : [];
        $ids = collect($past->keys())->merge($favIds)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $tenants = Tenant::query()->bookable()->whereIn('id', $ids)
            ->with(['branches' => fn ($q) => $q->where('status', 'active')->with(['city', 'district'])])->get()->keyBy('id');

        return $ids->filter(fn ($id) => $tenants->has($id))->take($limit)->map(function ($id) use ($tenants, $past, $favIds) {
            $last = $past[$id] ?? null;
            $tenant = $tenants[$id];

            return [
                'tenant' => $tenant, 'url' => $this->url($tenant, $last), 'last_at' => $last['last_at'] ?? null,
                'favorite' => in_array($id, $favIds, true),
                'branch' => $tenant->branches->firstWhere('id', $last['branch_id'] ?? 0) ?? $tenant->branches->first(),
            ];
        })->values();
    }

    /** Booking wizard URL with the previous choices pre-selected (the wizard re-validates them). */
    public function url(Tenant $tenant, ?array $last): string
    {
        if (! $last) {
            return route('salon.book', $tenant->slug);
        }

        return route('salon.book', array_filter([
            'slug' => $tenant->slug, 'sube' => $last['branch_id'],
            'hizmet' => $last['service_ids'] ? implode(',', $last['service_ids']) : null,
            'personel' => $last['employee_id'],
        ]));
    }
}
