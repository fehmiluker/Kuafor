<?php

namespace App\Http\Controllers\Web\Tenant;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidTransitionException;
use App\Http\Requests\Tenant\StaffAppointmentRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Service;
use App\Models\TenantCustomer;
use App\Rules\BelongsToCurrentTenant;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\Exclusions;
use App\Services\Catalog\PublicCatalog;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PanelAppointmentController extends PanelBaseController
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Appointment::class);
        $f = $request->validate(['durum' => ['nullable', Rule::in(AppointmentStatus::values())], 'sube' => ['nullable', 'integer'], 'tarih' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:100']]);
        $tz = $this->tenant()->timezone;
        $q = $this->scope()->scopeAppointments(Appointment::query(), $request->user(), $this->tenant())
            ->with(['items', 'employee', 'customer', 'branch'])
            ->when($f['durum'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['sube'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($f['tarih'] ?? null, function ($q, $d) use ($tz) {
                [$from, $to] = LocalTime::dayBoundsUtc($d, $tz);
                $q->where('starts_at_utc', '>=', $from)->where('starts_at_utc', '<', $to);
            })
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('public_reference', $term)
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))))
            ->orderByDesc('starts_at_utc');

        return view('tenant.appointments.index', [
            'appointments' => $q->paginate(25)->withQueryString(), 'filters' => $f,
            'branches' => $this->scope()->scopeBranches(Branch::query(), $request->user(), $this->tenant(), 'id')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Appointment::class);
        $branches = $this->scope()->scopeBranches(Branch::query(), $request->user(), $this->tenant(), 'id')->where('status', 'active')->orderBy('name')->get();

        return view('tenant.appointments.create', [
            'branches' => $branches,
            'services' => Service::where('active', true)->orderBy('name')->get(),
            'employees' => Employee::where('status', 'active')->with('branches')->orderBy('display_name')->get(),
            'customer' => $request->query('musteri') ? TenantCustomer::find($request->query('musteri')) : null,
            'prefill' => ['date' => $request->query('tarih'), 'time' => $request->query('saat'), 'employee_id' => $request->query('personel'), 'branch_id' => $request->query('sube')],
        ]);
    }

    public function store(StaffAppointmentRequest $request, PublicCatalog $catalog): RedirectResponse
    {
        $this->authorize('create', Appointment::class);
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);
        abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), $branch->id), 403);
        $customer = isset($data['customer_id']) ? TenantCustomer::findOrFail($data['customer_id']) : null;
        $result = $this->appointments->create($this->tenant(), new CreateAppointmentData(
            branchId: $branch->id, serviceIds: array_map('intval', $data['service_ids']), employeeId: $data['employee_id'] ?? null,
            startsAtUtc: $catalog->toUtc($branch, $data['date'], $data['time']),
            customerName: $customer?->full_name ?? $data['full_name'], customerPhoneE164: $customer?->phone_e164 ?? $data['phone'],
            customerEmail: $customer?->email ?? ($data['email'] ?? null), customerNote: $data['note'] ?? null,
            source: AppointmentSource::Panel, idempotencyKey: $request->input('_idem'), byStaff: true, staffUserId: $request->user()->id,
        ));

        return redirect()->route('tenant.appointments.show', $result['appointment']->id)->with('status', 'Randevu oluşturuldu.');
    }

    public function show(Request $request, Appointment $appointment, AvailabilityService $availability): View
    {
        $this->authorize('view', $appointment);
        $appointment->load(['items', 'employee', 'customer', 'branch', 'histories', 'payments', 'openProposal', 'proposals', 'review.reply']);
        $branch = $appointment->branch;
        $branch->setRelation('tenant', $this->tenant());
        $suggestions = [];
        if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed], true)) {
            $serviceIds = $appointment->items->pluck('service_id')->filter()->all();
            $date = LocalTime::toLocal($appointment->starts_at_utc, $branch->timezone)->startOfDay();
            for ($d = 0; $d < 3 && count($suggestions) < 12; $d++) {
                try {
                    foreach ($availability->slotsForDay($branch, $serviceIds, null, $date->addDays($d)->toDateString(), false, new Exclusions($appointment->id)) as $slot) {
                        if (count($suggestions) < 12 && ! $slot['start']->equalTo($appointment->starts_at_utc)) {
                            $suggestions[] = $slot;
                        }
                    }
                } catch (DomainRuleException) {
                    break;
                }
            }
        }

        return view('tenant.appointments.show', [
            'a' => $appointment, 'suggestions' => $suggestions,
            'employees' => Employee::where('status', 'active')->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))->orderBy('display_name')->get(),
            'can' => [
                'approve' => $request->user()->can('approve', $appointment), 'cancel' => $request->user()->can('cancel', $appointment),
                'complete' => $request->user()->can('complete', $appointment), 'reschedule' => $request->user()->can('reschedule', $appointment),
            ],
            'methods' => PaymentMethod::cases(),
        ]);
    }

    public function act(Request $request, Appointment $appointment, string $action, PublicCatalog $catalog): RedirectResponse
    {
        $actor = Actor::user($request->user(), 'panel');
        $branch = Branch::findOrFail($appointment->branch_id);
        $message = match ($action) {
            'onayla' => $this->authorizeThen('approve', $appointment, fn () => $this->appointments->approve($appointment, $actor), 'Randevu onaylandı.'),
            'reddet' => $this->authorizeThen('approve', $appointment, fn () => $this->appointments->reject($appointment, $actor, $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null), 'Talep reddedildi.'),
            'iptal' => $this->authorizeThen('cancel', $appointment, fn () => $this->appointments->cancel($appointment, $actor, $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null), 'Randevu iptal edildi.'),
            'gelmedi' => $this->authorizeThen('complete', $appointment, fn () => $this->appointments->markNoShow($appointment, $actor), '"Gelmedi" olarak işaretlendi.'),
            'tamamla' => $this->authorizeThen('complete', $appointment, function () use ($request, $appointment, $actor) {
                $d = $request->validate([
                    'discount' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'], 'amount' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
                    'method' => ['nullable', Rule::in(PaymentMethod::values())],
                ]);
                $payments = ! empty($d['amount']) ? [['amount' => $d['amount'], 'method' => $d['method'] ?? 'cash']] : [];

                return $this->appointments->complete($appointment, $actor, $payments, $d['discount'] ?? null);
            }, 'Randevu tamamlandı.'),
            'tahsilat' => $this->authorizeThen('complete', $appointment, function () use ($request, $appointment, $actor) {
                $d = $request->validate(['amount' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'], 'method' => ['required', Rule::in(PaymentMethod::values())], 'note' => ['nullable', 'string', 'max:500']]);

                return $this->appointments->recordPayment($appointment, $d, $actor);
            }, 'Tahsilat kaydedildi.'),
            'oner' => $this->authorizeThen('propose', $appointment, function () use ($request, $appointment, $actor, $branch, $catalog) {
                $d = $request->validate(['slot' => ['nullable', 'date'], 'date' => ['required_without:slot', 'nullable', 'date_format:Y-m-d'], 'time' => ['required_without:slot', 'nullable', 'date_format:H:i'],
                    'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')], 'note' => ['nullable', 'string', 'max:500']]);
                $start = ! empty($d['slot']) ? CarbonImmutable::parse($d['slot'])->utc() : $catalog->toUtc($branch, $d['date'], $d['time']);

                return $this->appointments->propose($appointment, $start, $d['employee_id'] ?? null, $actor, $d['note'] ?? null);
            }, 'Alternatif saat müşteriye gönderildi.'),
            'tasi' => $this->authorizeThen('reschedule', $appointment, function () use ($request, $appointment, $actor, $branch, $catalog) {
                $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')]]);

                return $this->appointments->reschedule($appointment, $catalog->toUtc($branch, $d['date'], $d['time']), $d['employee_id'] ?? null, $actor);
            }, 'Randevu taşındı.'),
        };

        return redirect()->route('tenant.appointments.show', $appointment->id)->with('status', $message);
    }

    /** FullCalendar feed: only appointments the user may see, in branch-local time. */
    public function events(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $d = $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date'], 'branch_id' => ['required', 'integer']]);
        abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), (int) $d['branch_id']), 403);
        $branch = Branch::findOrFail($d['branch_id']);
        $rows = $this->scope()->scopeAppointments(Appointment::query(), $request->user(), $this->tenant())
            ->with(['items', 'customer', 'employee'])->where('branch_id', $branch->id)
            ->whereNotIn('status', [AppointmentStatus::Rejected->value, AppointmentStatus::Expired->value])
            ->where('starts_at_utc', '<', CarbonImmutable::parse($d['end'])->utc())->where('ends_at_utc', '>', CarbonImmutable::parse($d['start'])->utc())
            ->limit(2000)->get();

        return response()->json($rows->map(fn (Appointment $a) => [
            'id' => $a->id,
            'title' => $a->customer->full_name.' — '.$a->serviceSummary(),
            'start' => $a->starts_at_utc->setTimezone($branch->timezone)->format('Y-m-d\TH:i:s'),
            'end' => $a->ends_at_utc->setTimezone($branch->timezone)->format('Y-m-d\TH:i:s'),
            'resourceId' => (string) $a->employee_id,
            'backgroundColor' => $a->status->color(), 'borderColor' => $a->status->color(),
            'editable' => $a->status === AppointmentStatus::Confirmed,
            'extendedProps' => ['status' => $a->status->label(), 'employee' => $a->employee?->display_name, 'employee_id' => $a->employee_id, 'url' => route('tenant.appointments.show', $a->id)],
        ]));
    }

    /** Drag & drop: authorization + full re-validation inside the booking lock. */
    public function drag(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('reschedule', $appointment);
        $d = $request->validate(['start' => ['required', 'date_format:Y-m-d\TH:i:s'], 'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')]]);
        $branch = Branch::findOrFail($appointment->branch_id);
        try {
            $a = $this->appointments->reschedule($appointment, CarbonImmutable::parse($d['start'], $branch->timezone)->utc(), $d['employee_id'] ?? null, Actor::user($request->user(), 'calendar'));
        } catch (BookingConflictException|DomainRuleException|InvalidTransitionException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'start' => $a->starts_at_utc->setTimezone($branch->timezone)->format('Y-m-d\TH:i:s')]);
    }

    private function authorizeThen(string $ability, Appointment $appointment, \Closure $fn, string $message): string
    {
        $this->authorize($ability, $appointment);
        $fn();

        return $message;
    }
}
