<?php

namespace App\Policies;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use App\Models\User;

class ServiceEnrollmentPolicy
{
    public function create(User $actor, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin();
    }

    public function close(User $actor, ServiceEnrollment $enrollment, Elderly $elderly): bool
    {
        return $actor->isActiveAdmin() && $enrollment->elderly_id === $elderly->id;
    }
}
