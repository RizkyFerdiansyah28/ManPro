<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class OwnershipPeriodSnapshot
{
    public function __construct(
        public string $id,
        public string $elderlyId,
        public string $familyId,
        public ?string $previousPeriodId,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $recordedBy,
        public string $recordedAt,
        public string $source,
    ) {}
}
