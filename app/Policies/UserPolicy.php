<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function updateStatus(User $actor, User $target): bool
    {
        return $actor->isActiveAdmin() && ! $actor->is($target)
            && in_array($target->role, User::MANAGED_ROLES, true);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $this->updateStatus($actor, $target);
    }
}
