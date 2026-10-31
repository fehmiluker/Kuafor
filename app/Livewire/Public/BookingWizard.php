<?php

namespace App\Livewire\Public;

use App\Enums\AppointmentSource;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Rules\Phone;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\PublicBookingService;
use App\Services\Catalog\PublicCatalog;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
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
        if ($service = (int) request()->query('hizmet')) {
            $this->serviceIds = [$service];
        }
        $this->date = CarbonImmutable::now($branches->first()->timezone)->toDateString();
        if ($user = auth()->user()) {
            $this->fullName = $user->name;
            $this->phone = (string) $user->phone_e164;
            $this->email = (string) $user->email;
        }
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
        $this->step = 6;
    }

    public function back(): void
    {
        $min = $this->tenantBranchCount() === 1 ? 2 : 1;
        $this->step = max($min, $this->step - 1);
    }

    public function confirm(PublicBookingService $booking): void
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
        $this->result = [
            'reference' => $a->public_reference,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'manage_url' => $r['guest_token'] ? route('guest.appointment', ['reference' => $a->public_reference, 'token' => $r['guest_token']]) : null,
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
        $services = $branch ? $catalog->services($branch) : collect();
        $selected = $services->whereIn('id', $this->serviceIds);
        $employees = $branch && $this->serviceIds ? $catalog->employees($branch, $this->serviceIds) : collect();
        $slots = [];
        $slotError = null;
        if ($branch && $this->step >= 4 && $this->serviceIds) {
            try {
                $slots = $availability->slotsForDay($branch, $this->serviceIds, $this->employeeId, $this->date);
            } catch (DomainRuleException $e) {
                $slotError = $e->getMessage();
            }
        }
        $settings = $branch ? BookingSettings::for($tenant, $branch) : null;

        return view('livewire.public.booking-wizard', [
            'tenant' => $tenant, 'branches' => $branches, 'branch' => $branch, 'services' => $services,
            'selected' => $selected, 'employees' => $employees, 'slots' => $slots, 'slotError' => $slotError,
            'total' => $selected->reduce(fn (Money $c, $s) => $c->plus($s->effective_price), Money::zero()),
            'duration' => $selected->sum('effective_duration'),
            'employee' => $this->employeeId ? Employee::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->employeeId) : null,
            'settings' => $settings,
        ])->title($tenant->name.' — Randevu al');
    }
}
