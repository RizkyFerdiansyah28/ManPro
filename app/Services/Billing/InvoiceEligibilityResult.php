<?php

declare(strict_types=1);

namespace App\Services\Billing;

// ISSUE hanya eligibility domain pada snapshot, bukan authorization/hasil guard DB/izin penerbitan.
final readonly class InvoiceEligibilityResult
{
    public function __construct(
        public InvoiceDecision $decision,
        public InvoiceReason $reason,
        public ?InvoiceCalculationResult $calculation = null,
        public ?string $ownershipPeriodId = null,
        public ?string $financialOwnerId = null,
    ) {}
}
