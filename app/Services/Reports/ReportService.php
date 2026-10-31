<?php

namespace App\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Tenant analytics. All money sums are done in SQL DECIMAL and converted via Money. */
final class ReportService
{
    /** @param list<int>|null $branchIds null = all */
    public function summary(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds = null): array
    {
        $base = DB::table('appointments')->where('appointments.tenant_id', $tenant->id)
            ->where('appointments.starts_at_utc', '>=', $from)->where('appointments.starts_at_utc', '<', $to)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('appointments.branch_id', $branchIds ?: [0]));

        $byStatus = (clone $base)->groupBy('appointments.status')->selectRaw('appointments.status, COUNT(*) c')->pluck('c', 'status')->all();
        $revenue = (clone $base)->where('appointments.status', AppointmentStatus::Completed->value)->sum('appointments.total_price');
        $collected = DB::table('appointment_payments')->where('appointment_payments.tenant_id', $tenant->id)
            ->join('appointments', 'appointments.id', '=', 'appointment_payments.appointment_id')
            ->where('appointments.starts_at_utc', '>=', $from)->where('appointments.starts_at_utc', '<', $to)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('appointments.branch_id', $branchIds ?: [0]))
            ->groupBy('appointment_payments.method')->selectRaw('appointment_payments.method, SUM(appointment_payments.amount) s')->pluck('s', 'method')->all();
        $byEmployee = (clone $base)->where('appointments.status', AppointmentStatus::Completed->value)
            ->join('employees', 'employees.id', '=', 'appointments.employee_id')
            ->groupBy('employees.id', 'employees.display_name')
            ->selectRaw('employees.display_name name, COUNT(*) c, SUM(appointments.total_price) s')->orderByDesc('s')->get();
        $topServices = DB::table('appointment_services')
            ->join('appointments', 'appointments.id', '=', 'appointment_services.appointment_id')
            ->where('appointments.tenant_id', $tenant->id)->where('appointments.status', AppointmentStatus::Completed->value)
            ->where('appointments.starts_at_utc', '>=', $from)->where('appointments.starts_at_utc', '<', $to)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('appointments.branch_id', $branchIds ?: [0]))
            ->groupBy('appointment_services.service_name_snapshot')
            ->selectRaw('appointment_services.service_name_snapshot name, COUNT(*) c')->orderByDesc('c')->limit(10)->get();

        $total = array_sum($byStatus);
        $noShow = (int) ($byStatus[AppointmentStatus::NoShow->value] ?? 0);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'appointments' => ['total' => $total, 'by_status' => $byStatus],
            'no_show_rate' => $total > 0 ? round($noShow / $total * 100, 1) : 0.0,
            'revenue_completed' => Money::parse((string) ($revenue ?: '0'))->jsonSerialize(),
            'collected_by_method' => array_map(fn ($v) => Money::parse((string) $v)->jsonSerialize(), $collected),
            'by_employee' => $byEmployee->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->c, 'revenue' => Money::parse((string) $r->s)->jsonSerialize()])->all(),
            'top_services' => $topServices->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->c])->all(),
            'note' => 'Tutarlar salonda tahsil edilen hizmet bedellerinin kayıtlarıdır; platform üzerinden ödeme alınmaz.',
        ];
    }
}
