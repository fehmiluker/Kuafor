<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Services\Tenancy\AccessScope;

class AppointmentPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'appointments.view');
    }

    public function view(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.view', $a->branch_id) && $this->employeeMayAccess($user, $a);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'appointments.create');
    }

    public function approve(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.approve', $a->branch_id);
    }

    public function propose(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.approve', $a->branch_id);
    }

    public function reschedule(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.reschedule', $a->branch_id);
    }

    public function cancel(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.cancel', $a->branch_id);
    }

    public function complete(User $user, Appointment $a): bool
    {
        return $this->check($user, $a, 'appointments.complete', $a->branch_id) && $this->employeeMayAccess($user, $a);
    }

    /** Employees (only role) may act on their own appointments only. */
    private function employeeMayAccess(User $user, Appointment $a): bool
    {
        $restricted = app(AccessScope::class)->restrictedEmployeeId($user, $this->tenant());

        return $restricted === null || $restricted === (int) $a->employee_id;
    }
}
