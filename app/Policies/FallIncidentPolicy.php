<?php

namespace App\Policies;

use App\Models\FallIncident;
use App\Models\User;

class FallIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'perawat'], true);
    }

    public function view(User $user, FallIncident $incident): bool
    {
        return $this->viewAny($user);
    }

    public function handle(User $user): bool
    {
        return $user->is_active && $user->role === 'perawat';
    }
}
