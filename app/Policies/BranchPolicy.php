<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'appointments.view') || $this->can($user, 'branches.manage');
    }

    public function view(User $user, Branch $b): bool
    {
        return $this->owns($b) && $this->inBranch($user, $b->id) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'branches.manage');
    }

    public function update(User $user, Branch $b): bool
    {
        return $this->check($user, $b, 'branches.manage', $b->id);
    }

    public function delete(User $user, Branch $b): bool
    {
        return $this->update($user, $b);
    }

    public function manageWhatsApp(User $user, Branch $b): bool
    {
        return $this->check($user, $b, 'whatsapp.manage', $b->id);
    }
}
