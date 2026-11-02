<?php

namespace App\Http\Controllers\Web\Tenant;

use App\Enums\TenantRole;
use App\Http\Requests\Tenant\BranchRequest;
use App\Http\Requests\Tenant\EmployeeRequest;
use App\Http\Requests\Tenant\ServiceRequest;
use App\Models\BlockedTime;
use App\Models\Branch;
use App\Models\City;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TenantCustomer;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Rules\BelongsToCurrentTenant;
use App\Rules\Phone;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Catalog\TenantCatalogService;
use App\Services\Onboarding\SalonOnboardingService;
use App\Services\Settings\SettingsRepository;
use App\Services\WhatsApp\ConnectionService;
use App\Support\LocalTime;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PanelCatalogController extends PanelBaseController
{
    public function __construct(private readonly TenantCatalogService $catalog, private readonly EntitlementService $entitlements) {}

    // ------------------------------------------------------------ branches

    public function branches(Request $request): View
    {
        $this->authorize('viewAny', Branch::class);
        $branches = $this->scope()->scopeBranches(Branch::query()->with(['city', 'district', 'whatsappConnection']), $request->user(), $this->tenant(), 'id')->orderBy('name')->get();

        return view('tenant.branches.index', ['branches' => $branches, 'usage' => $this->entitlements->usage($this->tenant()), 'canCreate' => $request->user()->can('create', Branch::class)]);
    }

    public function branchForm(Request $request, ?Branch $branch = null, ?SettingsRepository $settings = null): View
    {
        $branch ? $this->authorize('update', $branch) : $this->authorize('create', Branch::class);
        $settings ??= app(SettingsRepository::class);

        return view('tenant.branches.form', [
            'branch' => $branch?->load(['workingHours', 'serviceAreas']),
            'cities' => City::with('districts')->orderBy('name')->get(),
            'managerNumbers' => $branch ? (array) ($settings->branch($branch)['whatsapp_manager_numbers'] ?? []) : [],
            'blocked' => $branch ? BlockedTime::where('branch_id', $branch->id)->where('ends_at_utc', '>', now())->orderBy('starts_at_utc')->get() : collect(),
            'canAdd' => $this->entitlements->canAddBranch($this->tenant()),
        ]);
    }

    public function saveBranch(BranchRequest $request, ?Branch $branch = null): RedirectResponse
    {
        $branch ? $this->authorize('update', $branch) : $this->authorize('create', Branch::class);
        $data = $request->validated();
        $data['working_hours'] = collect($request->input('hours', []))->filter(fn ($h) => ! empty($h['open']) && ! empty($h['opens']) && ! empty($h['closes']))
            ->map(fn ($h, $dow) => ['day_of_week' => (int) $dow, 'opens' => $h['opens'], 'closes' => $h['closes']])->values()->all();
        if ($request->has('manager_numbers')) {
            $data['whatsapp_manager_numbers'] = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('manager_numbers')))));
            foreach ($data['whatsapp_manager_numbers'] as $n) {
                if (! PhoneNumber::isValid($n)) {
                    return back()->withInput()->withErrors(['manager_numbers' => "Geçersiz numara: {$n}"]);
                }
            }
        }
        if ($request->has('home_form')) {
            $data['home_service_enabled'] = $request->boolean('home_service_enabled');
            $data['home_requires_approval'] = $request->boolean('home_requires_approval');
            $areas = collect($request->input('area', []))->filter(fn ($a) => ! empty($a['on']))
                ->map(fn ($a, $districtId) => ['district_id' => (int) $districtId, 'fee' => $a['fee'] ?? null])->values();
            // Districts missing from the list (e.g. smaller cities) can be typed in; they are added to the city.
            $cityId = (int) ($data['city_id'] ?? $branch?->city_id);
            foreach (array_filter(array_map('trim', explode(',', (string) $request->input('extra_districts')))) as $name) {
                if ($cityId && ($id = SalonOnboardingService::resolveDistrict($cityId, $name))) {
                    $areas->push(['district_id' => $id, 'fee' => null]);
                }
            }
            foreach ($areas as $a) {
                if ($a['fee'] !== null && $a['fee'] !== '' && ! preg_match('/^\d{1,10}([.,]\d{1,2})?$/', (string) $a['fee'])) {
                    return back()->withInput()->withErrors(['home_areas' => 'Ulaşım ücreti geçersiz: '.$a['fee']]);
                }
            }
            $data['home_areas'] = $areas->unique('district_id')->values()->all();
            if ($data['home_service_enabled'] && $data['home_areas'] === []) {
                return back()->withInput()->withErrors(['home_areas' => 'Adrese hizmet için en az bir ilçe seçin.']);
            }
        }
        $saved = $this->catalog->saveBranch($branch, $data);

        return redirect()->route('tenant.branches.edit', $saved)->with('status', 'Şube kaydedildi.');
    }

    public function deleteBranch(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);
        $this->catalog->deleteBranch($branch);

        return redirect()->route('tenant.branches.index')->with('status', 'Şube silindi.');
    }

    public function addBlockedTime(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i', 'after:from'],
            'employee_id' => ['nullable', 'integer', new BelongsToCurrentTenant('employees')], 'reason' => ['nullable', 'string', 'max:300'],
        ]);
        BlockedTime::create([
            'branch_id' => $branch->id, 'employee_id' => $d['employee_id'] ?? null, 'reason' => $d['reason'] ?? null,
            'starts_at_utc' => LocalTime::toUtc($d['date'], $d['from'], $branch->timezone), 'ends_at_utc' => LocalTime::toUtc($d['date'], $d['to'], $branch->timezone),
        ]);

        return back()->with('status', 'Kapalı zaman eklendi.');
    }

    public function removeBlockedTime(Branch $branch, int $blocked): RedirectResponse
    {
        $this->authorize('update', $branch);
        BlockedTime::where('branch_id', $branch->id)->findOrFail($blocked)->delete();

        return back()->with('status', 'Kapalı zaman silindi.');
    }

    public function whatsapp(Request $request, Branch $branch): View
    {
        $this->authorize('manageWhatsApp', $branch);

        return view('tenant.branches.whatsapp', [
            'branch' => $branch, 'connection' => WhatsAppConnection::where('branch_id', $branch->id)->first(),
            'sender' => app(ConnectionService::class)->senderFor($this->tenant(), $branch),
            'embedded' => [
                'mode' => config('whatsapp.mode'),
                'app_id' => config('whatsapp.app_id'),
                'config_id' => config('whatsapp.embedded_signup_config_id'),
                'graph_version' => config('whatsapp.graph_version'),
            ],
            'messages' => WhatsAppMessage::where('branch_id', $branch->id)->with(['connection' => fn ($q) => $q->withoutGlobalScopes()])->latest()->limit(20)->get(),
            'managerNumbers' => (array) (app(SettingsRepository::class)->branch($branch)['whatsapp_manager_numbers'] ?? []),
        ]);
    }

    public function whatsappConnect(Request $request, Branch $branch, ConnectionService $connections): RedirectResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $d = $request->validate([
            'waba_id' => ['required', 'regex:/^\d{5,30}$/'], 'phone_number_id' => ['required', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:2000'], 'display_phone' => ['nullable', new Phone],
        ]);
        $connections->connect($branch, $d['waba_id'], $d['phone_number_id'], $d['access_token'], $d['display_phone'] ?? null);

        return back()->with('status', 'WhatsApp numarası bağlandı.');
    }

    /** Embedded Signup callback from the browser (FB.login code + waba/phone ids from Meta's popup). */
    public function whatsappEmbedded(Request $request, Branch $branch, ConnectionService $connections)
    {
        $this->authorize('manageWhatsApp', $branch);
        $d = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'waba_id' => ['required', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'regex:/^\d{5,30}$/'],
        ]);
        $c = $connections->connectViaEmbeddedSignup($branch, $d['code'], $d['waba_id'], $d['phone_number_id']);
        $message = 'WhatsApp numaranız bağlandı ('.($c->display_phone ?? $c->phone_number_id).'). Bildirimler artık kendi numaranızdan gidecek.';

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('status', $message);
    }

    public function whatsappDisconnect(Branch $branch, ConnectionService $connections): RedirectResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $connections->disconnect(WhatsAppConnection::where('branch_id', $branch->id)->firstOrFail());

        return back()->with('status', 'WhatsApp bağlantısı kaldırıldı. Bildirimler e-posta ve panel üzerinden devam eder.');
    }

    // ------------------------------------------------------------ employees

    public function employees(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);
        $ids = $this->scope()->branchIds($request->user(), $this->tenant());
        $employees = Employee::with(['branches', 'services', 'user'])
            ->when($ids !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $ids ?: [0])))->orderBy('display_name')->get();

        return view('tenant.employees.index', ['employees' => $employees, 'usage' => $this->entitlements->usage($this->tenant()), 'overLimit' => $this->entitlements->overLimit($this->tenant())]);
    }

    public function employeeForm(Request $request, ?Employee $employee = null): View
    {
        $employee ? $this->authorize('update', $employee) : $this->authorize('create', Employee::class);
        $employee?->load(['branches', 'services', 'user', 'workingHours', 'leaves' => fn ($q) => $q->where('ends_at_utc', '>', now())]);

        return view('tenant.employees.form', [
            'employee' => $employee,
            'branches' => $this->scope()->scopeBranches(Branch::query(), $request->user(), $this->tenant(), 'id')->orderBy('name')->get(),
            'services' => Service::where('active', true)->orderBy('name')->get(),
            'roles' => array_filter(TenantRole::cases(), fn ($r) => $r !== TenantRole::Owner),
            'canAdd' => $this->entitlements->canAddEmployee($this->tenant()),
        ]);
    }

    public function saveEmployee(EmployeeRequest $request, ?Employee $employee = null): RedirectResponse
    {
        $employee ? $this->authorize('update', $employee) : $this->authorize('create', Employee::class);
        foreach ($request->input('branch_ids', []) as $bid) {
            abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), (int) $bid), 403);
        }
        $data = $request->validated();
        $data['shifts'] = collect($request->input('shift', []))->flatMap(fn ($days, $branchId) => collect($days)
            ->filter(fn ($h) => ! empty($h['on']) && ! empty($h['opens']) && ! empty($h['closes']))
            ->map(fn ($h, $dow) => ['branch_id' => (int) $branchId, 'day_of_week' => (int) $dow, 'opens' => $h['opens'], 'closes' => $h['closes']]))->values()->all();
        if (! $request->boolean('custom_shifts')) {
            $data['shifts'] = [];
        }
        $data['home_service'] = $request->boolean('home_service');
        $saved = $this->catalog->saveEmployee($employee, $data);

        return redirect()->route('tenant.employees.edit', $saved)->with('status', 'Personel kaydedildi.');
    }

    public function deleteEmployee(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);
        $this->catalog->deleteEmployee($employee);

        return redirect()->route('tenant.employees.index')->with('status', 'Personel silindi.');
    }

    public function addLeave(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'reason' => ['nullable', 'string', 'max:300']]);
        $tz = $this->tenant()->timezone;
        EmployeeLeave::create([
            'employee_id' => $employee->id, 'reason' => $d['reason'] ?? null, 'status' => 'approved',
            'starts_at_utc' => LocalTime::toUtc($d['from'], '00:00', $tz), 'ends_at_utc' => LocalTime::toUtc($d['to'], '00:00', $tz)->addDay(),
        ]);
        $conflicts = DB::table('appointments')->where('employee_id', $employee->id)->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at_utc', '<', LocalTime::toUtc($d['to'], '00:00', $tz)->addDay())->where('blocked_until_utc', '>', LocalTime::toUtc($d['from'], '00:00', $tz))->count();

        return back()->with('status', 'İzin eklendi.'.($conflicts ? " Dikkat: bu tarihlerde {$conflicts} randevu var; lütfen taşıyın." : ''));
    }

    // ------------------------------------------------------------ services

    public function services(Request $request): View
    {
        $this->authorize('viewAny', Service::class);

        return view('tenant.services.index', ['services' => Service::with(['category', 'branchServices'])->orderBy('name')->get(), 'canManage' => $request->user()->can('create', Service::class)]);
    }

    public function serviceForm(Request $request, ?Service $service = null): View
    {
        $service ? $this->authorize('update', $service) : $this->authorize('create', Service::class);

        return view('tenant.services.form', [
            'service' => $service?->load('branchServices'),
            'categories' => ServiceCategory::visibleToTenant()->where('status', 'active')->orderBy('sort_order')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function saveService(ServiceRequest $request, ?Service $service = null): RedirectResponse
    {
        $service ? $this->authorize('update', $service) : $this->authorize('create', Service::class);
        $data = $request->validated();
        $data['active'] = $request->boolean('active');
        $data['home_available'] = $request->boolean('home_available');
        $data['branch_ids'] = $data['branch_ids'] ?? [];
        $saved = $this->catalog->saveService($service, $data);

        return redirect()->route('tenant.services.index')->with('status', '"'.$saved->name.'" kaydedildi.');
    }

    public function deleteService(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);
        $this->catalog->deleteService($service);

        return redirect()->route('tenant.services.index')->with('status', 'Hizmet silindi.');
    }

    // ------------------------------------------------------------ customers

    public function customers(Request $request): View
    {
        $this->authorize('viewAny', TenantCustomer::class);
        $term = trim((string) $request->query('q'));
        $phone = PhoneNumber::normalize($term);
        $customers = TenantCustomer::withCount('appointments')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')->when($phone, fn ($p) => $p->orWhere('phone_e164', $phone))))
            ->orderByDesc('last_visit_at')->orderBy('full_name')->paginate(25)->withQueryString();

        return view('tenant.customers.index', compact('customers', 'term'));
    }

    public function customer(Request $request, TenantCustomer $customer, AuditLogger $audit): View
    {
        $this->authorize('view', $customer);
        $audit->log('customer.viewed', $customer);
        $appointments = $this->scope()->scopeAppointments($customer->appointments()->getQuery(), $request->user(), $this->tenant())
            ->with(['items', 'employee', 'branch'])->latest('starts_at_utc')->paginate(20);

        return view('tenant.customers.show', ['customer' => $customer, 'appointments' => $appointments, 'canEdit' => $request->user()->can('update', $customer)]);
    }

    public function updateCustomer(Request $request, TenantCustomer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);
        $d = $request->validate(['full_name' => ['required', 'string', 'min:2', 'max:150'], 'email' => ['nullable', 'email:rfc'], 'notes' => ['nullable', 'string', 'max:5000'], 'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])]]);
        $customer->forceFill($d)->save();

        return back()->with('status', 'Müşteri kartı güncellendi.');
    }
}
