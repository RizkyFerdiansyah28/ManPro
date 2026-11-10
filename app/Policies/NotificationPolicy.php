<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Support\StaffNotification;

class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'perawat'], true);
    }

    public function read(User $user, Notification $notification): bool
    {
        return $this->viewAny($user) && $notification->notifiable_type === StaffNotification::MORPH
            && $notification->notifiable_id === $user->id && $notification->type === StaffNotification::KIND;
    }
}
