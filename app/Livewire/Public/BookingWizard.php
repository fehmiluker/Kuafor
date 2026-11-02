<?php

namespace App\Livewire\Public;

use App\DTOs\Appointments\HomeVisit;
use App\Enums\AppointmentSource;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Models\Appointment;
use App\Models\AppointmentAddress;
use App\Models\Branch;
use App\Models\CustomerFavorite;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Rules\Phone;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\HomeVisitRules;
use App\Services\Booking\PublicBookingService;
use App\Services\Catalog\BookingLinkService;
use App\Services\Catalog\PublicCatalog;
use App\Services\Catalog\RebookService;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mobile-first booking flow. Tenant/branch ids are #[Locked] (cannot be
 * tampered with from the browser) and everything is re-validated server-side
 * by AppointmentService inside the booking lock.
 */
#[Layout('layouts.public')]
#[Title('Randevu al')]
class BookingWizard extends Component
{
    #[Locked]
    public int $tenantId;

    #[Locked]
    public string $slug;

    #[Locked]
    public string $idempotencyKey;

    public int $step = 1;

    public ?int $branchId = null;

    /** @var list<int> */
    public array $serviceIds = [];

    public ?int $employeeId = null;

    public string $date = '';

    public ?string $time = null;

    public string $fullName = '';

    public string $phone = '';

    public string $email = '';

    public string $note = '';

    public bool $kvkk = false;

    public bool $whatsappOptIn = true;

    public bool $marketingOptIn = false;

    public string $code = '';

    public bool $codeSent = false;

    public string $website = ''; // honeypot

    /** "salon" or "address" (home/workplace visit). */
    public string $location = 'salon';

    public ?int $districtId = null;

    public string $placeType = 'home';

    public string $addressLine = '';

    public string $addressDetail = '';

    public ?array $result = null;

    public function mount(string $slug, PublicCatalog $catalog): void
    {
        $tenant = $catalog->bookableTenant($slug) ?? abort(404);
        $this->tenantId = $tenant->id;
        $this->slug = $slug;
        $this->idempotencyKey = (string) Str::uuid();
        $branches = $catalog->branches($tenant);
        abort_if($branches->isEmpty(), 404);
        $requested = (int) request()->query('sube');
        if ($branches->count() === 1 || $branches->contains('id', $requested)) {
            $this->branchId = $branches->count() === 1 ? $branches->first()->id : $requested;
            $this->step = 2;
        }
        if (request()->query('yer') === 'adres' && $this->branchId && app(HomeVisitRules::class)->offers($this->branch())) {
            $this->location = 'address';
        }
        $this->prefill($catalog, (string) request()->query('hizmet', ''), (int) request()->query('personel'));
        $this->date = CarbonImmutable::now($branches->first()->timezone)->toDateString();
        if ($user = auth()->user()) {
            $this->fullName = $user->name;
            $this->phone = (string) $user->phone_e164;
            $this->email = (string) $user->email;
            $this->prefillAddress($user->id);
        }
    }

    /** Logged-in customer: last (not yet purged) address used at this salon. */
    private function prefillAddress(int $userId): void
    {
        $last = AppointmentAddress::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereNull('purged_at')
            ->whereIn('appointment_id', Appointment::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
                ->whereIn('customer_id', TenantCustomer::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->where('user_id', $userId)->select('id'))->select('id'))
            ->latest('id')->first();
        if ($last) {
            $this->districtId = $last->district_id;
            $this->placeType = $last->place_type;
            $this->addressLine = (string) $last->address_line;
            $this->addressDetail = (string) $last->address_detail;
        }
    }

    public function setLocation(string $location): void
    {
        $location = $location === 'address' && $this->branchId && app(HomeVisitRules::class)->offers($this->branch()) ? 'address' : 'salon';
        if ($location !== $this->location) {
            $this->location = $location;
            $this->serviceIds = [];
            $this->employeeId = null;
            $this->time = null;
        }
    }

    /**
     * "Tekrar randevu al" links pre-select services (?hizmet=1,2) and an employee (?personel=3).
     * Only choices still offered by this branch are kept; the booking itself is re-validated anyway.
     */
    private function prefill(PublicCatalog $catalog, string $services, int $employeeId): void
    {
        $requested = array_values(array_unique(array_filter(array_map('intval', explode(',', $services)))));
        if ($requested === []) {
            return;
        }
        if (! $this->branchId) {
            $this->serviceIds = array_slice($requested, 0, 1);

            return;
        }
        $branch = $this->branch();
        $offered = $catalog->services($branch)->when($this->location === 'address', fn ($c) => $c->where('home_available', true))->pluck('id')->all();
        $this->serviceIds = array_slice(array_values(array_intersect($requested, $offered)), 0, (int) config('booking.max_services_per_appointment'));
        if ($this->serviceIds === []) {
            return;
        }
        $this->step = 3;
        if ($employeeId && $catalog->employees($branch, $this->serviceIds)->when($this->location === 'address', fn ($c) => $c->where('home_service', true))->contains('id', $employeeId)) {
            $this->employeeId = $employeeId;
            $this->step = 4;
        }
    }

    public function addFavorite(): void
    {
        $user = auth()->user();
        abort_unless($user && $this->result, 403);
        CustomerFavorite::firstOrCreate(['user_id' => $user->id, 'tenant_id' => $this->tenantId]);
        $this->result['favorite'] = true;
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail($this->tenantId);
    }

    private function branch(): ?Branch
    {
        if (! $this->branchId) {
            return null;
        }
        $branch = app(PublicCatalog::class)->bookableBranch($this->branchId);
        abort_if(! $branch || $branch->tenant_id !== $this->tenantId, 404);

        return $branch;
    }

    public function selectBranch(int $id): void
    {
        $this->branchId = $id;
        $this->branch();
        $this->serviceIds = [];
        $this->employeeId = null;
        $this->time = null;
        $this->step = 2;
    }

    public function toggleService(int $id): void
    {
        $this->serviceIds = in_array($id, $this->serviceIds, true)
            ? array_values(array_diff($this->serviceIds, [$id]))
            : array_slice(array_merge($this->serviceIds, [$id]), 0, (int) config('booking.max_services_per_appointment'));
        $this->time = null;
    }

    public function toEmployees(): void
    {
        if ($this->serviceIds === []) {
            $this->addError('serviceIds', 'En az bir hizmet seçin.');

            return;
        }
        if ($this->location === 'address' && ($min = $this->branch()?->home_min_total)) {
            $sum = app(PublicCatalog::class)->services($this->branch())->whereIn('id', $this->serviceIds)
                ->reduce(fn (Money $c, $s) => $c->plus($s->effective_price), Money::zero());
            if ($min->greaterThan($sum)) {
                $this->addError('serviceIds', 'Adrese hizmet için en düşük hizmet tutarı '.$min->format().'.');

                return;
            }
        }
        $this->step = 3;
    }

    public function selectEmployee(?int $id): void
    {
        $this->employeeId = $id;
        $this->time = null;
        $this->step = 4;
    }

    public function updatedDate(): void
    {
        $this->time = null;
    }

    public function shiftDate(int $days): void
    {
        $tz = $this->branch()?->timezone ?? 'Europe/Istanbul';
        $new = CarbonImmutable::parse($this->date ?: 'today', $tz)->addDays($days);
        if ($new->lt(CarbonImmutable::now($tz)->startOfDay())) {
            return;
        }
        $this->date = $new->toDateString();
        $this->time = null;
    }

    public function selectTime(string $time): void
    {
        $this->time = $time;
        $this->step = 5;
    }

    public function sendCode(PhoneVerificationService $otp): void
    {
        $this->validate(['phone' => ['required', new Phone]]);
        try {
            $otp->send(PhoneNumber::normalize($this->phone), 'booking', null, request()->ip());
            $this->codeSent = true;
        } catch (DomainRuleException $e) {
            $this->addError('phone', $e->getMessage());
        }
    }

    public function toSummary(): void
    {
        $this->validate([
            'fullName' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', new Phone],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'kvkk' => ['accepted'],
        ], ['kvkk.accepted' => 'Devam etmek için aydınlatma metnini okuduğunuzu onaylayın.', 'fullName.required' => 'Ad soyad zorunludur.']);
        if ($this->location === 'address') {
            $areas = app(HomeVisitRules::class)->areas($this->branch());
            $this->validate([
                'districtId' => ['required', 'integer', 'in:'.$areas->keys()->implode(',')],
                'placeType' => ['required', 'in:home,work'],
                'addressLine' => ['required', 'string', 'min:5', 'max:300'],
                'addressDetail' => ['nullable', 'string', 'max:300'],
            ], ['districtId.required' => 'İlçe seçin.', 'districtId.in' => 'Salon bu ilçeye hizmet vermiyor.', 'addressLine.required' => 'Açık adres zorunludur.', 'addressLine.min' => 'Açık adresi biraz daha ayrıntılı yazın.']);
        }
        $this->step = 6;
    }

    public function back(): void
    {
        $min = $this->tenantBranchCount() === 1 ? 2 : 1;
        $this->step = max($min, $this->step - 1);
    }

    public function confirm(PublicBookingService $booking, BookingLinkService $links, RebookService $rebook): void
    {
        if ($this->website !== '') {
            abort(422);
        }
        $key = 'wizard:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('booking', 'Çok fazla deneme yaptınız. Lütfen biraz sonra tekrar deneyin.');

            return;
        }
        RateLimiter::hit($key, 60);
        $branch = $this->branch() ?? abort(404);
        try {
            $r = $booking->book($branch, [
                'service_ids' => $this->serviceIds, 'employee_id' => $this->employeeId, 'date' => $this->date, 'time' => $this->time,
                'full_name' => $this->fullName, 'phone' => PhoneNumber::normalize($this->phone), 'email' => $this->email ?: null,
                'note' => $this->note ?: null, 'whatsapp_opt_in' => $this->whatsappOptIn, 'marketing_opt_in' => $this->marketingOptIn,
                'verification_code' => $this->code ?: null,
                'home_visit' => $this->location === 'address' ? new HomeVisit((int) $this->districtId, $this->addressLine, $this->addressDetail ?: null, $this->placeType) : null,
            ], auth()->user(), AppointmentSource::Web, $this->idempotencyKey, request()->ip());
        } catch (BookingConflictException $e) {
            $this->addError('booking', $e->getMessage());
            $this->time = null;
            $this->step = 4;

            return;
        } catch (DomainRuleException $e) {
            $this->addError('booking', $e->getMessage());

            return;
        }
        $a = $r['appointment'];
        $links->booked($this->tenantId);
        // "Son gittiğim salon": remembered on this device even without an account.
        Cookie::queue($rebook->rememberCookie($this->tenantId, $branch->id, $this->serviceIds, $this->employeeId ?? $a->employee_id));
        $user = auth()->user();
        $this->result = [
            'reference' => $a->public_reference,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'manage_url' => $r['guest_token'] ? route('guest.appointment', ['reference' => $a->public_reference, 'token' => $r['guest_token']]) : null,
            'favorite' => $user ? CustomerFavorite::where('user_id', $user->id)->where('tenant_id', $this->tenantId)->exists() : false,
        ];
        $this->step = 7;
    }

    private function tenantBranchCount(): int
    {
        return app(PublicCatalog::class)->branches($this->tenant())->count();
    }

    public function render(PublicCatalog $catalog, AvailabilityService $availability)
    {
        $tenant = $this->tenant();
        $branches = $catalog->branches($tenant);
        $branch = $this->branch();
        $home = $this->location === 'address';
        $rules = app(HomeVisitRules::class);
        $areas = $branch && $rules->offers($branch) ? $rules->areas($branch) : collect();
        $services = $branch ? $catalog->services($branch)->when($home, fn ($c) => $c->where('home_available', true)->values()) : collect();
        $selected = $services->whereIn('id', $this->serviceIds);
        $employees = $branch && $this->serviceIds ? $catalog->employees($branch, $this->serviceIds)->when($home, fn ($c) => $c->where('home_service', true)->values()) : collect();
        $slots = [];
        $slotError = null;
        if ($branch && $this->step >= 4 && $this->serviceIds) {
            try {
                $slots = $availability->slotsForDay($branch, $this->serviceIds, $this->employeeId, $this->date, homeVisit: $home);
            } catch (DomainRuleException $e) {
                $slotError = $e->getMessage();
            }
        }
        $settings = $branch ? BookingSettings::for($tenant, $branch) : null;

        $subtotal = $selected->reduce(fn (Money $c, $s) => $c->plus($s->effective_price), Money::zero());
        $travelFee = $home && $this->districtId && $areas->has($this->districtId) ? ($areas[$this->districtId]->travel_fee ?? Money::zero()) : Money::zero();

        return view('livewire.public.booking-wizard', [
            'areas' => $areas, 'home' => $home, 'travelFee' => $travelFee, 'subtotal' => $subtotal,
            'belowMinimum' => $home && $branch?->home_min_total && $branch->home_min_total->greaterThan($subtotal),
            'tenant' => $tenant, 'branches' => $branches, 'branch' => $branch, 'services' => $services,
            'selected' => $selected, 'employees' => $employees, 'slots' => $slots, 'slotError' => $slotError,
            'total' => $subtotal->plus($travelFee),
            'duration' => $selected->sum('effective_duration'),
            'employee' => $this->employeeId ? Employee::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->employeeId) : null,
            'settings' => $settings,
        ])->title($tenant->name.' — Randevu al');
    }
}
