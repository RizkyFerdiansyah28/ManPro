<?php

namespace App\Policies;

use App\Models\ServiceClass;
use App\Models\User;

class ServiceClassPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function view(User $actor, ServiceClass $serviceClass): bool
    {
        return $actor->isActiveAdmin();
    }

    public function update(User $actor, ServiceClass $serviceClass): bool
    {
        return $actor->isActiveAdmin();
    }

    public function updateStatus(User $actor, ServiceClass $serviceClass): bool
    {
        return $actor->isActiveAdmin();
    }
}
