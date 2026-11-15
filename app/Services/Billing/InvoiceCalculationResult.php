<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceCalculationResult
{
    public function __construct(
        public string $billingMonth,
        public ?string $effectiveStartedAt,
        public ?string $effectiveEndedAt,
        public int $chargeableDays,
        public int $daysInMonth,
        public string $monthlyRate,
        // NULL berarti tidak ada charge interval, bukan invoice nol.
        public ?string $amount,
        public string $policyVersion,
    ) {}
}
