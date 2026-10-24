<?php

namespace App\Policies;

use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\User;

class ElderlyStayPolicy
{
    public function viewAny(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function create(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function close(User $actor, ElderlyStay $stay, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin() && $stay->elderly_id === $elderly->id;
    }
}
