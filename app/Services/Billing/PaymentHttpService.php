<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Support\Billing\PaymentCommandContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentHttpService
{
    public function __construct(private readonly PaymentHttpReader $reader, private readonly PaymentService $payments,
        private readonly LocalPaymentProofStore $proofs, private readonly PaymentCommandContext $contexts) {}

    public function context(Request $request, string $invoice, ?string $payment = null): array
    {
        $root = $this->reader->invoice($request->user(), $invoice);
        $operation = $payment ? $request->validated('operation') : 'submit';
        if ($payment) {
            $this->reader->payment($request->user(), $invoice, $payment);
            $state = $this->payments->reviewState($request->user()->id, $invoice, $payment);
        } else {
            if ($root->status !== 'unpaid' || $root->amount === '0.00'
                || DB::table('public.payments')->where('invoice_id', $invoice)->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new PaymentFailure('invoice_not_eligible');
            }
            $state = null;
        }

        return ['command_context' => $this->contexts->issue($request, 'command', ['operation' => $operation,
            'invoice_id' => $invoice, 'revision_id' => $root->current_revision_id, 'payment_id' => $payment,
            'proof_reference' => $payment ? null : (string) Str::uuid(), 'expected_state' => $state]), 'expires_in' => 300];
    }

    public function submit(Request $request, string $invoice): PaymentResult
    {
        $this->reader->invoice($request->user(), $invoice);
        $facts = $this->commandFacts($request, 'submit', $invoice);
        $this->proofs->intake($request->file('proof'), $facts['proof_reference'], $request->user()->id, $invoice);

        return $this->payments->submit(new PaymentCommand($facts + ['confirm' => true]));
    }

    public function review(Request $request, string $invoice, string $payment, string $operation): PaymentResult
    {
        $this->reader->payment($request->user(), $invoice, $payment);
        $facts = $this->commandFacts($request, $operation, $invoice, $payment);
        $command = new PaymentCommand($facts + ['confirm' => true, 'bank_receipt_checked' => $operation === 'verify',
            ...($operation === 'reject' ? $request->safe()->only(['reason_code', 'public_reason', 'private_note']) : [])]);

        return $this->payments->{$operation}($command);
    }

    public function reconcile(Request $request): PaymentResult
    {
        $this->contexts->read($request, $request->input('recovery_context'), 'recovery');
        $data = $this->contexts->read($request, $request->input('command_context'), 'command', true);
        $facts = $this->facts($request, $data);
        $this->reader->invoice($request->user(), $facts['invoice_id']);
        $prior = DB::table('public.billing_requests')->where('actor_id', $request->user()->id)
            ->where('request_id', $facts['request_id'])->where('invoice_id', $facts['invoice_id'])->first();
        if (! $prior) {
            throw new PaymentFailure('outcome_unresolved');
        }
        if ($prior->operation !== $facts['operation'] || $prior->outcome_revision_id !== $facts['revision_id']) {
            throw new PaymentFailure('idempotency_conflict');
        }
        $extra = ['confirm' => true];
        if ($facts['operation'] !== 'submit') {
            $row = $this->reader->payment($request->user(), $facts['invoice_id'], $facts['payment_id']);
            $action = DB::table('public.billing_actions')->where('billing_request_id', $prior->id)
                ->where('payment_id', $row->id)->where('actor_id', $request->user()->id)
                ->where('action', $facts['operation'] === 'verify' ? 'payment_verified' : 'payment_rejected')->first();
            if (! $action || $prior->outcome_payment_id !== $row->id) {
                throw new PaymentFailure('integrity_violation');
            }
            $details = json_decode($action->details, true, 16, JSON_THROW_ON_ERROR);
            if ($facts['operation'] === 'verify' && ($details['bank_receipt_checked'] ?? null) !== true) {
                throw new PaymentFailure('integrity_violation');
            }
            $extra['bank_receipt_checked'] = $facts['operation'] === 'verify';
            if ($facts['operation'] === 'reject') {
                $extra += ['reason_code' => $action->reason_code, 'public_reason' => $row->public_rejection_reason, 'private_note' => $action->private_note];
            }
        }

        // Core recomputes canonical hash and only replays a committed command.
        return $this->payments->reconcile(new PaymentCommand($facts + $extra));
    }

    private function commandFacts(Request $request, string $operation, string $invoice, ?string $payment = null): array
    {
        $data = $this->contexts->read($request, $request->input('command_context'), 'command');
        if (($data['operation'] ?? null) !== $operation || ($data['invoice_id'] ?? null) !== $invoice || ($data['payment_id'] ?? null) !== $payment) {
            throw new PaymentFailure('context_invalid');
        }

        return $this->facts($request, $data);
    }

    private function facts(Request $request, array $data): array
    {
        return ['actor_id' => $request->user()->id, ...array_intersect_key($data, array_flip([
            'operation', 'invoice_id', 'revision_id', 'payment_id', 'request_id', 'proof_reference', 'expected_state',
        ]))];
    }
}
