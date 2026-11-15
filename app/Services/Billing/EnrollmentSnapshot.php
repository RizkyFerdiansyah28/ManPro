<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class EnrollmentSnapshot
{
    public function __construct(
        public string $id,
        public string $elderlyId,
        public string $serviceClassId,
        public string $startedAt,
        public ?string $endedAt,
        public string|int|float|bool|null $monthlyPrice,
    ) {}
}
