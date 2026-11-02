<?php

namespace App\Http\Controllers\Web\Public;

use App\Enums\SalonApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Admin\AdminOnboardingController;
use App\Models\City;
use App\Models\SalonApplication;
use App\Models\ServiceCategory;
use App\Rules\Phone;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Public "Salonunu ekle": salon owners apply, a platform admin sets the salon up after review. */
class SalonApplicationController extends Controller
{
    public function create(SettingsRepository $settings): View
    {
        return view('public.salon-application', [
            'enabled' => AdminOnboardingController::applicationsEnabled($settings),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(['slug', 'name']),
        ]);
    }

    public function store(Request $request, SettingsRepository $settings, AuditLogger $audit): RedirectResponse
    {
        abort_unless(AdminOnboardingController::applicationsEnabled($settings), 404);
        // Honeypot: bots fill every field; pretend success.
        if ($request->filled('website')) {
            return redirect()->route('salon-application.create')->with('status', 'Başvurunuz alındı.');
        }
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        }
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', new Phone],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'district_name' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:300'],
            'categories' => ['nullable', 'array', 'max:10'],
            'categories.*' => ['string', Rule::exists('service_categories', 'slug')->whereNull('tenant_id')],
            'employee_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'kvkk_acknowledged' => ['accepted'],
        ], [], [
            'business_name' => 'İşletme adı', 'contact_name' => 'Ad soyad', 'email' => 'E-posta', 'phone' => 'Telefon', 'city_id' => 'İl',
            'district_name' => 'İlçe', 'address' => 'Adres', 'employee_count' => 'Çalışan sayısı', 'note' => 'Not', 'kvkk_acknowledged' => 'Aydınlatma metni',
        ]);

        $duplicate = SalonApplication::where('email', $data['email'])->where('business_name', $data['business_name'])
            ->where('status', SalonApplicationStatus::Pending->value)->exists();
        if (! $duplicate) {
            $application = (new SalonApplication)->forceFill([
                'business_name' => $data['business_name'], 'contact_name' => $data['contact_name'], 'email' => $data['email'],
                'phone_e164' => $data['phone'], 'city_id' => $data['city_id'], 'district_name' => $data['district_name'] ?? null,
                'address' => $data['address'], 'categories' => $data['categories'] ?? [], 'employee_count' => $data['employee_count'] ?? null,
                'note' => $data['note'] ?? null, 'kvkk_acknowledged_at' => now(), 'status' => SalonApplicationStatus::Pending,
            ]);
            $application->save();
            $audit->log('salon_application.created', $application);
        }

        return redirect()->route('salon-application.create')
            ->with('status', 'Başvurunuz alındı. Ekibimiz bilgilerinizi inceleyip en kısa sürede sizinle iletişime geçecek.');
    }
}
