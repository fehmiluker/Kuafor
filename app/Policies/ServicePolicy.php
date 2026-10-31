<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'appointments.view');
    }

    public function view(User $user, Service $s): bool
    {
        return $this->owns($s) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'services.manage');
    }

    public function update(User $user, Service $s): bool
    {
        return $this->check($user, $s, 'services.manage');
    }

    public function delete(User $user, Service $s): bool
    {
        return $this->update($user, $s);
    }
}
