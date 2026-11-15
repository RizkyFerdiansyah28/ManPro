<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class OwnershipActionSnapshot
{
    public function __construct(
        public string $periodId,
        public string $actorId,
        public string $action,
        public string $occurredAt,
    ) {}
}
