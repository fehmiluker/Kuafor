<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Http\Requests\Tenant\StaffAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\TenantCustomer;
use App\Rules\BelongsToCurrentTenant;
use App\Services\Booking\AppointmentService;
use App\Services\Catalog\PublicCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends TenantApiController
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(AppointmentStatus::values())],
            'branch_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $q = $this->scope()->scopeAppointments(Appointment::query(), $request->user(), $this->tenant())
            ->with(['items', 'branch', 'employee', 'customer'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('starts_at_utc', '>=', CarbonImmutable::parse($d)->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('starts_at_utc', '<', CarbonImmutable::parse($d)->utc()))
            ->orderBy('starts_at_utc');
        $collection = AppointmentResource::collection($q->paginate(50));
        $collection->collection->each->forStaff();

        return ApiResponse::ok($collection);
    }

    public function store(StaffAppointmentRequest $request, PublicCatalog $catalog): JsonResponse
    {
        $this->authorize('create', Appointment::class);
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);
        abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), $branch->id), 403);
        $customer = isset($data['customer_id']) ? TenantCustomer::findOrFail($data['customer_id']) : null;

        $result = $this->appointments->create($this->tenant(), new CreateAppointmentData(
            branchId: $branch->id,
            serviceIds: array_map('intval', $data['service_ids']),
            employeeId: $data['employee_id'] ?? null,
            startsAtUtc: $catalog->toUtc($branch, $data['date'], $data['time']),
            customerName: $customer?->full_name ?? $data['full_name'],
            customerPhoneE164: $customer?->phone_e164 ?? $data['phone'],
            customerEmail: $customer?->email ?? ($data['email'] ?? null),
            customerNote: $data['note'] ?? null,
            source: AppointmentSource::Panel,
            idempotencyKey: $request->header('Idempotency-Key'),
            byStaff: true,
            staffUserId: $request->user()->id,
        ));

        return ApiResponse::ok($this->present($result['appointment']), [], $result['created'] ? 201 : 200);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return ApiResponse::ok($this->present($appointment));
    }

    public function approve(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('approve', $appointment);

        return ApiResponse::ok($this->present($this->appointments->approve($appointment, Actor::user($request->user(), 'api'))));
    }

    public function reject(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('approve', $appointment);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::ok($this->present($this->appointments->reject($appointment, Actor::user($request->user(), 'api'), $data['reason'] ?? null)));
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::ok($this->present($this->appointments->cancel($appointment, Actor::user($request->user(), 'api'), $data['reason'] ?? null)));
    }

    public function complete(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('complete', $appointment);
        $data = $request->validate([
            'discount' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'payments' => ['sometimes', 'array', 'max:5'],
            'payments.*.amount' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'payments.*.method' => ['required', Rule::in(PaymentMethod::values())],
            'payments.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::ok($this->present($this->appointments->complete($appointment, Actor::user($request->user(), 'api'), $data['payments'] ?? [], $data['discount'] ?? null)));
    }

    public function noShow(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('complete', $appointment);

        return ApiResponse::ok($this->present($this->appointments->markNoShow($appointment, Actor::user($request->user(), 'api'))));
    }

    public function propose(Request $request, Appointment $appointment, PublicCatalog $catalog): JsonResponse
    {
        $this->authorize('propose', $appointment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'],
            'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $branch = Branch::findOrFail($appointment->branch_id);
        $r = $this->appointments->propose($appointment, $catalog->toUtc($branch, $data['date'], $data['time']), $data['employee_id'] ?? null, Actor::user($request->user(), 'api'), $data['note'] ?? null);

        return ApiResponse::created(['proposal_id' => $r['proposal']->id, 'expires_at' => $r['proposal']->expires_at_utc->toIso8601ZuluString()], [
            'appointment' => $this->present($appointment->fresh()),
        ]);
    }

    public function reschedule(Request $request, Appointment $appointment, PublicCatalog $catalog): JsonResponse
    {
        $this->authorize('reschedule', $appointment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'],
            'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')],
        ]);
        $branch = Branch::findOrFail($appointment->branch_id);
        if (isset($data['employee_id'])) {
            abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), $branch->id), 403);
        }
        $a = $this->appointments->reschedule($appointment, $catalog->toUtc($branch, $data['date'], $data['time']), $data['employee_id'] ?? null, Actor::user($request->user(), 'api'));

        return ApiResponse::ok($this->present($a));
    }

    public function recordPayment(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('complete', $appointment);
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->appointments->recordPayment($appointment, $data, Actor::user($request->user(), 'api'));

        return ApiResponse::created($this->present($appointment->fresh()));
    }

    private function present(Appointment $a): array
    {
        $a = AppointmentService::withRelations($a);
        $a->load(['payments', 'openProposal']);

        return (new AppointmentResource($a))->forStaff()->resolve(request());
    }
}
