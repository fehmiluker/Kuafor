<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\Tenancy\AccessScope;
use Illuminate\Support\Facades\DB;

class EmployeePolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'appointments.view');
    }

    public function view(User $user, Employee $e): bool
    {
        return $this->owns($e) && $this->viewAny($user) && $this->sharesBranch($user, $e);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'employees.manage');
    }

    public function update(User $user, Employee $e): bool
    {
        return $this->owns($e) && $this->can($user, 'employees.manage') && $this->sharesBranch($user, $e);
    }

    public function delete(User $user, Employee $e): bool
    {
        return $this->update($user, $e);
    }

    /** Branch-scoped managers only see employees working in one of their branches. */
    private function sharesBranch(User $user, Employee $e): bool
    {
        $branchIds = DB::table('employee_branches')->where('employee_id', $e->id)->pluck('branch_id');
        if ($branchIds->isEmpty()) {
            return $this->inBranch($user, null) && app(AccessScope::class)->branchIds($user, $this->tenant()) === null;
        }

        return $branchIds->contains(fn ($id) => $this->inBranch($user, (int) $id));
    }
}
