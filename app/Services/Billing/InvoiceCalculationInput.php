<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceCalculationInput
{
    public function __construct(
        public string $billingMonth,
        public string $startedAt,
        public ?string $endedAt,
        // Scalar invalid dapat direpresentasikan tanpa coercion; calculator menolak selain string exact.
        public string|int|float|bool|null $monthlyPrice,
        public string $policyVersion,
    ) {}
}
