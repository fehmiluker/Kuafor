<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'reviews.reply');
    }

    public function reply(User $user, Review $r): bool
    {
        return $this->check($user, $r, 'reviews.reply', $r->branch_id);
    }

    public function report(User $user, Review $r): bool
    {
        return $this->reply($user, $r);
    }

    /** Businesses can never delete reviews. */
    public function delete(User $user, Review $r): bool
    {
        return false;
    }
}
