<?php

declare(strict_types=1);

namespace App\Services\Billing;

final class PaymentCanonical
{
    public function intent(PaymentCommand $command, ?StoredPaymentProof $proof): string
    {
        $d = $command->data;

        return InvoiceCanonical::digest('silansia.payment.command.intent.v1', [
            $d['operation'], $d['actor_id'], $d['invoice_id'], $d['revision_id'], $d['payment_id'],
            $d['proof_reference'], $proof ? array_values($proof->row()) : null, $d['expected_state'],
            $d['confirm'], $d['bank_receipt_checked'], $d['reason_code'], $d['public_reason'], $d['private_note'],
        ]);
    }

    public function state(object $invoice, object $payment): string
    {
        return InvoiceCanonical::digest('silansia.payment.review.state.v1', [
            $invoice->id, $invoice->current_revision_id, $invoice->status, $invoice->amount, $invoice->paid_at,
            $invoice->bill_to_family_id, $payment->id, $payment->invoice_id, $payment->invoice_revision_id,
            $payment->status, $payment->amount, $payment->submitted_by, $payment->submitted_at,
            $payment->verified_by, $payment->verified_at, $payment->public_rejection_reason, $payment->verification_notes,
            array_values(StoredPaymentProof::fromRow($payment)->row()),
        ]);
    }
}
