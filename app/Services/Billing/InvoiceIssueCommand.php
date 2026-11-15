<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceIssueCommand
{
    public string $actorId;

    public string $elderlyId;

    public string $requestId;

    public function __construct(
        string $actorId,
        string $elderlyId,
        public string $billingMonth,
        string $requestId,
        public InvoiceIssueConfirmation $confirmation,
    ) {
        foreach ([$actorId, $elderlyId, $requestId] as $id) {
            if (! BillingTime::uuid($id)) {
                throw new InvoiceIssuanceFailure('invalid_command');
            }
        }
        try {
            BillingTime::month($billingMonth);
        } catch (InvoiceCalculationException) {
            throw new InvoiceIssuanceFailure('invalid_command');
        }
        $this->actorId = strtolower($actorId);
        $this->elderlyId = strtolower($elderlyId);
        $this->requestId = strtolower($requestId);
    }
}
