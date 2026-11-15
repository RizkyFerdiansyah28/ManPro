<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceSourceSnapshot
{
    public function __construct(
        public string $elderlyId,
        public string $currentFamilyId,
        public bool $residentActive,
        public array $enrollments,
        public array $periods,
        public array $actions,
        public ?string $existingInvoiceId,
    ) {}

    public function eligibility(string $month, string $at): InvoiceEligibilityInput
    {
        return new InvoiceEligibilityInput($this->elderlyId, $this->currentFamilyId, $this->residentActive,
            $month, $at, InvoiceCalculator::POLICY_VERSION, $this->existingInvoiceId,
            $this->enrollments, $this->periods, $this->actions);
    }
}
