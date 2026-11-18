<?php

declare(strict_types=1);

namespace App\Services\Billing;

// No production issuer/store is provided in P9-4B. No raw metadata input is trusted.
class PaymentProofStore
{
    public function resolve(string $reference, string $submitter, string $invoice): StoredPaymentProof
    {
        throw new PaymentFailure('proof_boundary_unavailable');
    }

    public function assertStored(StoredPaymentProof $proof, string $submitter, string $invoice): void
    {
        throw new PaymentFailure('proof_boundary_unavailable');
    }
}
