<?php

declare(strict_types=1);

namespace App\Services\Billing;

final class PaymentReadiness
{
    public function __construct(private readonly InvoiceIssuanceReadiness $invoice, private readonly InvoiceSchemaContract $schema) {}

    public function entry(): void
    {
        if (config('payment_core.enabled') !== true) {
            throw new PaymentFailure('gate_closed');
        }
        try {
            $this->invoice->entry();
            $this->schema->assertReady();
        } catch (InvoiceIssuanceFailure $error) {
            throw new PaymentFailure($error->kind);
        }
    }

    public function transaction(): void
    {
        try {
            $this->invoice->transaction();
        } catch (InvoiceIssuanceFailure $error) {
            throw new PaymentFailure($error->kind);
        }
    }
}
