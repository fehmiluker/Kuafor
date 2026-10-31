<?php

namespace App\Policies;

use App\Models\TenantCustomer;
use App\Models\User;

class TenantCustomerPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'customers.view');
    }

    public function view(User $user, TenantCustomer $c): bool
    {
        return $this->check($user, $c, 'customers.view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'customers.manage');
    }

    public function update(User $user, TenantCustomer $c): bool
    {
        return $this->check($user, $c, 'customers.manage');
    }

    public function delete(User $user, TenantCustomer $c): bool
    {
        return $this->update($user, $c);
    }
}
