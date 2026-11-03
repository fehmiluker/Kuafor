<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\SalonApplicationStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PlanLimitException;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Plan;
use App\Models\SalonApplication;
use App\Models\ServiceCategory;
use App\Notifications\SalonApplicationRejected;
use App\Rules\Phone;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\SalonProfileService;
use App\Services\Onboarding\SalonCsvImporter;
use App\Services\Onboarding\SalonOnboardingService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin > Salon ekleme: bulk CSV import of salons that agreed to join, and the
 * "Salonunu ekle" applications (approve = create salon + invite, reject).
 */
class AdminOnboardingController extends Controller
{
    public const APPLICATIONS_SETTING = 'salon_applications_enabled';

    private const IMPORT_DIR = 'imports';

    public function __construct(private readonly AuditLogger $audit) {}

    private function need(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('tenants.manage'), 403);
    }

    public function createForm(Request $request): View
    {
        $this->need($request);

        return view('admin.onboarding.create', [
            'cities' => City::orderBy('name')->get(),
            'plans' => Plan::where('active', true)->orderBy('sort_order')->get(),
            'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(),
        ]);
    }

    public function createSalon(Request $request, SalonOnboardingService $onboarding, SalonProfileService $profiles): RedirectResponse
    {
        $this->need($request);
        $d = $request->validate([
            'business_name' => ['required', 'string', 'max:150'], 'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:254'], 'owner_phone' => ['nullable', new Phone],
            'city_id' => ['required', 'integer', 'exists:cities,id'], 'district_name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:2000'],
            'plan' => ['required', Rule::exists('plans', 'slug')->where('active', true)],
            'category' => ['nullable', Rule::exists('service_categories', 'slug')->whereNull('tenant_id')->where('status', 'active')],
            'services' => ['nullable', 'string', 'max:5000'], 'employees' => ['nullable', 'string', 'max:2000'],
            'invite' => ['nullable', 'boolean'],
            'contact_phone' => ['nullable', new Phone],
            ...SalonProfileService::rules(),
        ]);
        [$services, $errors] = SalonCsvImporter::parseServices((string) ($d['services'] ?? ''));
        if ($errors) {
            return back()->withInput()->withErrors(['services' => implode(' ', $errors)]);
        }
        if (! empty($d['category'])) {
            $services = array_map(fn ($s) => array_replace($s, ['category' => $d['category']]), $services);
        }
        $d['owner_phone'] ??= null;
        $d['services'] = $services;
        $d['employees'] = array_values(array_filter(array_map(fn ($n) => Str::limit(trim($n), 60, ''), preg_split('/[|\r\n]+/', (string) ($d['employees'] ?? '')) ?: [])));
        ['tenant' => $tenant, 'user' => $user] = DB::transaction(function () use ($d, $onboarding, $profiles) {
            $result = $onboarding->create($d, 'admin');
            $tenant = $result['tenant'];
            if (! empty($d['contact_phone'])) {
                $tenant->branches()->orderBy('id')->first()?->forceFill(['phone' => $d['contact_phone']])->save();
            }
            $this->audit->log('admin.salon_created', $tenant, [], ['name' => $tenant->name], tenantId: $tenant->id);
            $profiles->save($tenant, $d);

            return $result;
        });
        if ($request->boolean('invite')) {
            $onboarding->invite($user, $tenant);
        }

        return redirect()->route('admin.tenants.show', $tenant->id)->with('status', 'İşletme oluşturuldu.');
    }

    // ------------------------------------------------------------ bulk import

    public function importForm(Request $request): View
    {
        $this->need($request);

        return view('admin.onboarding.import', ['rows' => null, 'token' => null, 'columns' => SalonCsvImporter::COLUMNS]);
    }

    public function template(Request $request): Response
    {
        $this->need($request);

        return response(SalonCsvImporter::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="salon-aktarma-sablonu.csv"',
        ]);
    }

    public function preview(Request $request, SalonCsvImporter $importer): View|RedirectResponse
    {
        $this->need($request);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], [], ['file' => 'CSV dosyası']);
        $token = Str::random(32);
        $request->file('file')->storeAs(self::IMPORT_DIR, $token.'.csv', 'local');
        try {
            $rows = $importer->parse(Storage::disk('local')->path(self::IMPORT_DIR."/{$token}.csv"));
        } catch (DomainRuleException $e) {
            Storage::disk('local')->delete(self::IMPORT_DIR."/{$token}.csv");

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return view('admin.onboarding.import', ['rows' => $rows, 'token' => $token, 'columns' => SalonCsvImporter::COLUMNS]);
    }

    public function import(Request $request, SalonCsvImporter $importer): RedirectResponse
    {
        $this->need($request);
        $data = $request->validate(['token' => ['required', 'alpha_num', 'size:32'], 'invite' => ['nullable', 'boolean']]);
        $path = self::IMPORT_DIR."/{$data['token']}.csv";
        abort_unless(Storage::disk('local')->exists($path), 404);
        @set_time_limit(0);
        // Re-validated at import time: data may have changed since the preview.
        $rows = $importer->parse(Storage::disk('local')->path($path));
        $result = $importer->import($rows, (bool) ($data['invite'] ?? false));
        Storage::disk('local')->delete($path);
        $skipped = count(array_filter($rows, fn ($r) => $r['data'] === null));
        $this->audit->log('admin.salons_imported', null, [], ['created' => $result['created'], 'failed' => count($result['failed']), 'skipped' => $skipped, 'invited' => (bool) ($data['invite'] ?? false)]);

        return redirect()->route('admin.onboarding.import')
            ->with('status', "{$result['created']} salon eklendi.".($skipped ? " {$skipped} hatalı satır atlandı." : '').($result['failed'] ? ' '.count($result['failed']).' satır eklenemedi.' : ''))
            ->with('import_failed', $result['failed']);
    }

    // ------------------------------------------------------------ applications

    public function applications(Request $request, SettingsRepository $settings): View
    {
        $this->need($request);
        $status = SalonApplicationStatus::tryFrom((string) $request->query('durum', 'pending'));
        $applications = SalonApplication::with('city')->when($status, fn ($q) => $q->where('status', $status->value))->latest()->paginate(25)->withQueryString();
        $counts = SalonApplication::select('status', DB::raw('COUNT(*) c'))->groupBy('status')->pluck('c', 'status');

        return view('admin.onboarding.applications', [
            'applications' => $applications, 'status' => $status, 'counts' => $counts,
            'enabled' => self::applicationsEnabled($settings),
        ]);
    }

    public function toggleApplications(Request $request, SettingsRepository $settings): RedirectResponse
    {
        $this->need($request);
        $enabled = $request->boolean('enabled');
        $settings->setPlatform([self::APPLICATIONS_SETTING => $enabled]);
        $this->audit->log('admin.salon_applications_toggled', null, [], ['enabled' => $enabled]);

        return back()->with('status', $enabled ? '"Salonunu ekle" başvuru formu açıldı.' : '"Salonunu ekle" başvuru formu kapatıldı.');
    }

    public function application(Request $request, SalonApplication $application): View
    {
        $this->need($request);

        return view('admin.onboarding.application', [
            'a' => $application->load(['city', 'tenant', 'decidedBy']),
            'plans' => Plan::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function approve(Request $request, SalonApplication $application, SalonOnboardingService $onboarding): RedirectResponse
    {
        $this->need($request);
        abort_unless($application->status === SalonApplicationStatus::Pending, 409);
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'slug')->where('active', true)],
            'services' => ['nullable', 'string', 'max:5000'],
            'employees' => ['nullable', 'string', 'max:2000'],
            'invite' => ['nullable', 'boolean'],
        ]);
        [$services, $errors] = SalonCsvImporter::parseServices((string) ($data['services'] ?? ''));
        if ($errors) {
            return back()->withInput()->withErrors(['services' => implode(' ', $errors)]);
        }
        $employees = array_values(array_filter(array_map(fn ($n) => Str::limit(trim($n), 60, ''), preg_split('/[|\r\n]+/', (string) ($data['employees'] ?? '')) ?: [])));

        try {
            ['tenant' => $tenant, 'user' => $user] = $onboarding->create([
                'business_name' => $application->business_name, 'owner_name' => $application->contact_name, 'owner_email' => $application->email,
                'owner_phone' => $application->phone_e164, 'city_id' => $application->city_id, 'district_name' => $application->district_name,
                'address' => $application->address, 'plan' => $data['plan'], 'services' => $services, 'employees' => $employees,
            ], 'application');
        } catch (DomainRuleException|PlanLimitException $e) {
            return back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        }
        $application->forceFill(['status' => SalonApplicationStatus::Approved, 'tenant_id' => $tenant->id,
            'decided_by_user_id' => $request->user()->id, 'decided_at' => now()])->save();
        if ($request->boolean('invite', true)) {
            $onboarding->invite($user, $tenant);
        }
        $this->audit->log('admin.salon_application_approved', $application, [], ['tenant_id' => $tenant->id]);

        return redirect()->route('admin.tenants.show', $tenant->id)->with('status', "Başvuru onaylandı, {$tenant->name} oluşturuldu.".($request->boolean('invite', true) ? ' Davet e-postası gönderildi.' : ''));
    }

    public function reject(Request $request, SalonApplication $application): RedirectResponse
    {
        $this->need($request);
        abort_unless($application->status === SalonApplicationStatus::Pending, 409);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500'], 'notify' => ['nullable', 'boolean']]);
        $application->forceFill(['status' => SalonApplicationStatus::Rejected, 'rejection_reason' => $data['reason'],
            'decided_by_user_id' => $request->user()->id, 'decided_at' => now()])->save();
        if ($request->boolean('notify')) {
            Notification::route('mail', $application->email)->notify(new SalonApplicationRejected($application));
        }
        $this->audit->log('admin.salon_application_rejected', $application, [], ['reason' => $data['reason']]);

        return redirect()->route('admin.onboarding.applications')->with('status', 'Başvuru reddedildi.');
    }

    public static function applicationsEnabled(SettingsRepository $settings): bool
    {
        return (bool) ($settings->platform()[self::APPLICATIONS_SETTING] ?? true);
    }
}
