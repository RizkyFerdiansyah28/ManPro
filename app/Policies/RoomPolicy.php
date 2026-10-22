<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function view(User $actor, Room $room): bool
    {
        return $actor->isActiveAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function update(User $actor, Room $room): bool
    {
        return $actor->isActiveAdmin();
    }

    public function updateStatus(User $actor, Room $room): bool
    {
        return $actor->isActiveAdmin();
    }
}
