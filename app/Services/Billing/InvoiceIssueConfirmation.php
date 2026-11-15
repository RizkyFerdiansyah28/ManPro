<?php

declare(strict_types=1);

namespace App\Services\Billing;

// Konteks internal trusted caller, bukan token HTTP atau bukti authorization.
final readonly class InvoiceIssueConfirmation
{
    public function __construct(public string $fingerprint)
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $fingerprint)) {
            throw new InvoiceIssuanceFailure('invalid_command');
        }
    }
}
