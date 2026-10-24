<?php

namespace App\Policies;

use App\Models\Camera;
use App\Models\User;

class CameraPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function view(User $actor, Camera $camera): bool
    {
        return $actor->isActiveAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function update(User $actor, Camera $camera): bool
    {
        return $actor->isActiveAdmin();
    }

    public function updateStatus(User $actor, Camera $camera): bool
    {
        return $actor->isActiveAdmin();
    }
}
