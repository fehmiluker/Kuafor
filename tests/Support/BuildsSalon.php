<?php

namespace Tests\Support;

use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\AppointmentSource;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SalonBuilder;

/**
 * Standard fixture: a Profesyonel-plan salon with one Istanbul branch open
 * 09:00–19:00 on weekdays, two services and two employees.
 * Time is frozen to Monday 2026-11-02 08:00 Istanbul (05:00 UTC).
 */
trait BuildsSalon
{
    protected Tenant $tenant;

    protected Branch $branch;

    protected Service $cut;   // 30 min, 10 min buffer, 300 TL

    protected Service $color; // 90 min, 0 buffer, 1200 TL

    protected Employee $ayse;

    protected Employee $mehmet;

    protected SalonBuilder $builder;

    protected function buildSalon(string $approval = 'automatic', string $plan = 'profesyonel', string $name = 'Işıltı Kuaför'): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Istanbul'));
        $this->builder = SalonBuilder::make()->tenant($name, $this->plan($plan));
        $this->builder->settings(['approval_mode' => $approval, 'min_notice_minutes' => 30, 'slot_interval_minutes' => 15]);
        $this->branch = $this->builder->branch('Merkez');
        $this->cut = $this->builder->service('Saç Kesimi', 30, '300.00', [$this->branch], 10, 'sac-kesim');
        $this->color = $this->builder->service('Saç Boyama', 90, '1200.00', [$this->branch], 0, 'sac-boya');
        $this->ayse = $this->builder->employee('Ayşe', [$this->branch], [$this->cut, $this->color]);
        $this->mehmet = $this->builder->employee('Mehmet', [$this->branch], [$this->cut]);
        $this->tenant = $this->builder->get();
        $this->branch->setRelation('tenant', $this->tenant);
    }

    /** Local Istanbul wall time on the frozen Monday (or another date). */
    protected function at(string $time, string $date = '2026-11-02'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Europe/Istanbul')->utc();
    }

    protected function bookingData(CarbonImmutable $start, ?int $employeeId = null, array $services = [], string $phone = '+905551112233', array $extra = []): CreateAppointmentData
    {
        return new CreateAppointmentData(...array_merge([
            'branchId' => $this->branch->id,
            'serviceIds' => $services ?: [$this->cut->id],
            'employeeId' => $employeeId,
            'startsAtUtc' => $start,
            'customerName' => 'Zeynep Kaya',
            'customerPhoneE164' => $phone,
            'source' => AppointmentSource::Web,
        ], $extra));
    }

    protected function book(CarbonImmutable $start, ?int $employeeId = null, array $services = [], string $phone = '+905551112233', array $extra = []): array
    {
        return app(AppointmentService::class)->create($this->tenant, $this->bookingData($start, $employeeId, $services, $phone, $extra));
    }
}
