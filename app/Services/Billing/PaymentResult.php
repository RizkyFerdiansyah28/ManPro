<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class PaymentResult
{
    public function __construct(public string $operation, public string $invoiceId, public string $revisionId,
        public string $paymentId, public string $amount, public string $recordedAt, public bool $replayed = false) {}

    // Command facts, never a claim about the current mutable payment status.
    public function fact(): string
    {
        return match ($this->operation) {
            'submit' => 'submission_recorded', 'verify' => 'verification_recorded', 'reject' => 'rejection_recorded',
        };
    }
}
