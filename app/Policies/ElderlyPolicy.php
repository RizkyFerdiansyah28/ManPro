<?php

namespace App\Policies;

use App\Models\Elderly;
use App\Models\User;

class ElderlyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, ['admin', 'keluarga'], true);
    }

    public function view(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin() || ($actor->is_active && $actor->role === 'keluarga'
            && $actor->id === $elderly->family_id);
    }

    public function create(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function update(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function updateStatus(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function reassignFamily(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function selectFamily(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }
}
