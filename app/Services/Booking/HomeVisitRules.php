<?php

namespace App\Services\Booking;

use App\DTOs\Appointments\HomeVisit;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\BranchServiceArea;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Business rules for home visits; enforced server-side on every booking (the wizard only mirrors them). */
final class HomeVisitRules
{
    /** @return Collection<int, BranchServiceArea> keyed by district_id */
    public function areas(Branch $branch): Collection
    {
        return BranchServiceArea::withoutGlobalScopes()->where('branch_id', $branch->id)->with('district.city')
            ->get()->sortBy(fn ($a) => $a->district?->name)->keyBy('district_id');
    }

    public function offers(Branch $branch): bool
    {
        return $branch->home_service_enabled && $this->areas($branch)->isNotEmpty();
    }

    /**
     * Validates the visit and returns its travel fee.
     *
     * @param  Collection<int, Service>  $services
     */
    public function assertBookable(Branch $branch, HomeVisit $visit, Collection $services): Money
    {
        if (! $branch->home_service_enabled) {
            throw new DomainRuleException('Bu şube adrese hizmet vermiyor.', 'home_service_unavailable');
        }
        $area = $this->areas($branch)->get($visit->districtId)
            ?? throw new DomainRuleException('Bu salon seçtiğiniz ilçeye hizmet vermiyor.', 'outside_service_area');
        if (trim($visit->addressLine) === '' || ! in_array($visit->placeType, ['home', 'work'], true)) {
            throw new DomainRuleException('Lütfen açık adresinizi girin.', 'address_required');
        }
        $notHome = $services->first(fn (Service $s) => ! $s->home_available);
        if ($notHome) {
            throw new DomainRuleException("\"{$notHome->name}\" hizmeti adreste verilmiyor.", 'service_not_home');
        }

        return $area->travel_fee ?? Money::zero();
    }

    public function assertMinimum(Branch $branch, Money $subtotal): void
    {
        $min = $branch->home_min_total;
        if ($min && $min->greaterThan($subtotal)) {
            throw new DomainRuleException('Adrese hizmet için en düşük hizmet tutarı '.$min->format().'.', 'home_min_total');
        }
    }
}
