<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceIssuePreview
{
    public function __construct(public InvoiceIssueConfirmation $confirmation, public InvoiceEligibilityResult $eligibility) {}
}
