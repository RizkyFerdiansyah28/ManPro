<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class PaymentPolicy
{
    public function submit(User $actor, object $invoice, string $currentFamily): bool
    {
        return $actor->is_active && $actor->role === 'keluarga'
            && $actor->id === $invoice->bill_to_family_id && $actor->id === $currentFamily;
    }

    public function review(User $actor): bool
    {
        return $actor->isActiveAdmin();
    }
}
