<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceIssueResult
{
    public function __construct(
        public InvoiceDecision $decision,
        public InvoiceReason $reason,
        public ?string $invoiceId = null,
        public ?string $revisionId = null,
        public ?string $amount = null,
        public ?string $issuedAt = null,
        public bool $replayed = false,
    ) {}
}
