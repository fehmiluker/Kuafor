<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\TenantCustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\TenantCustomer;
use App\Rules\Phone;
use App\Services\Audit\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tenant-private CRM. Viewing a customer's notes is logged as sensitive access. */
class CustomerController extends TenantApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantCustomer::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = TenantCustomer::query()->withCount('appointments')->orderByDesc('last_visit_at')->orderBy('full_name');
        if (! empty($data['q'])) {
            $term = str_replace(['%', '_'], ['\%', '\_'], $data['q']);
            $phone = PhoneNumber::normalize($data['q']);
            $q->where(fn ($w) => $w->where('full_name', 'like', "%{$term}%")->when($phone, fn ($p) => $p->orWhere('phone_e164', $phone)));
        }

        return ApiResponse::ok(TenantCustomerResource::collection($q->paginate(25)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TenantCustomer::class);
        $data = $this->validated($request);
        $c = TenantCustomer::create($data);

        return ApiResponse::created(new TenantCustomerResource($c));
    }

    public function show(Request $request, TenantCustomer $customer, AuditLogger $audit): JsonResponse
    {
        $this->authorize('view', $customer);
        $audit->log('customer.viewed', $customer);
        $customer->showNotes = true;
        $appointments = $this->scope()->scopeAppointments($customer->appointments()->getQuery(), $request->user(), $this->tenant())
            ->with(['items', 'branch', 'employee'])->latest('starts_at_utc')->limit(50)->get();

        return ApiResponse::ok(new TenantCustomerResource($customer), [
            'appointments' => AppointmentResource::collection($appointments)->resolve($request),
        ]);
    }

    public function update(Request $request, TenantCustomer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        $customer->forceFill($this->validated($request, $customer))->save();

        return ApiResponse::ok(new TenantCustomerResource($customer));
    }

    public function destroy(TenantCustomer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);
        $customer->delete(); // soft delete: appointment history stays consistent

        return ApiResponse::ok(['deleted' => true]);
    }

    private function validated(Request $request, ?TenantCustomer $c = null): array
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        }
        $req = $c ? 'sometimes' : 'required';
        $data = $request->validate([
            'full_name' => [$req, 'string', 'min:2', 'max:150'],
            'phone' => [$req, new Phone, Rule::unique('tenant_customers', 'phone_e164')->where('tenant_id', $this->tenant()->id)->ignore($c?->id)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'blocked'])],
        ]);
        if (isset($data['phone'])) {
            $data['phone_e164'] = $data['phone'];
            unset($data['phone']);
        }

        return $data;
    }
}
