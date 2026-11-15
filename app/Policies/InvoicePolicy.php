<?php

namespace App\Policies;

use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function view(User $actor, string $invoice): bool
    {
        return $actor->isActiveAdmin();
    }

    public function preview(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    public function reconcile(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }

    // Dipanggil internal writer setelah semua calon Users terkunci, termasuk replay.
    public function issue(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }
}
