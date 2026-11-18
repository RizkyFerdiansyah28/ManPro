<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use App\Policies\PaymentPolicy;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly PaymentReadiness $readiness,
        private readonly PaymentProofStore $proofs,
        private readonly PaymentCanonical $canonical,
        private readonly PaymentPolicy $policy,
    ) {}

    public function submit(PaymentCommand $command): PaymentResult
    {
        return $this->command($command, 'submit');
    }

    public function verify(PaymentCommand $command): PaymentResult
    {
        return $this->command($command, 'verify');
    }

    public function reject(PaymentCommand $command): PaymentResult
    {
        return $this->command($command, 'reject');
    }

    public function reconcile(PaymentCommand $original): PaymentResult
    {
        // Nonlocking committed-candidate lookup: an uncommitted first request is not
        // a reason to wait for its user/invoice locks, nor to run an initial mutation.
        $this->readiness->entry();
        $d = $original->data;
        if (! Auth::check() || Auth::id() !== $d['actor_id']) {
            throw new PaymentFailure('unauthorized');
        }
        if (DB::transactionLevel() !== 0) {
            throw new PaymentFailure('outer_transaction_required');
        }
        $this->authorize(User::find($d['actor_id']), $this->root($d['invoice_id']),
            $d['payment_id'] ? $this->payment($d['invoice_id'], $d['payment_id']) : null, $d['operation'], $d['payment_id']);
        if (! DB::table('public.billing_requests')->where('actor_id', $d['actor_id'])->where('request_id', $d['request_id'])->exists()) {
            throw new PaymentFailure('outcome_unresolved');
        }

        return $this->run($original, true);
    }

    // Internal optimistic state, not an HTTP token or authorization capability; no TTL.
    public function reviewState(string $actor, string $invoice, string $payment): string
    {
        foreach ([$actor, $invoice, $payment] as $id) {
            if (! BillingTime::uuid($id)) {
                throw new PaymentFailure('invalid_command');
            }
        }

        return $this->locked(strtolower($actor), strtolower($invoice), strtolower($payment), 'verify',
            function ($root, $row) {
                if (! $row || $row->status !== 'pending' || $root->status !== 'unpaid') {
                    throw new PaymentFailure('stale_payment_state');
                }

                return $this->canonical->state($root, $row);
            });
    }

    private function command(PaymentCommand $command, string $operation): PaymentResult
    {
        if ($command->data['operation'] !== $operation) {
            throw new PaymentFailure('invalid_command');
        }

        return $this->run($command, false);
    }

    private function run(PaymentCommand $command, bool $reconcile): PaymentResult
    {
        $d = $command->data;
        $proof = null;
        $proofError = null;
        // Proof is prepared outside financial locks. A failed byte check is not a reason to
        // hide an already committed command from an otherwise authorized replay.
        $prepare = function ($root, $row) use ($d, $reconcile, &$proof, &$proofError): void {
            if ($d['operation'] === 'submit') {
                $proof = $this->proofs->resolve($d['proof_reference'], $d['actor_id'], $d['invoice_id']);
            }
            if (! $reconcile && ($d['operation'] === 'submit' || $d['operation'] === 'verify')) {
                try {
                    $this->proofs->assertStored($proof ?? StoredPaymentProof::fromRow($row),
                        $d['operation'] === 'submit' ? $d['actor_id'] : $row->submitted_by, $d['invoice_id']);
                } catch (PaymentFailure $error) {
                    $proofError = $error;
                }
            }
        };

        return $this->locked($d['actor_id'], $d['invoice_id'], $d['payment_id'], $d['operation'],
            function ($root, $row) use ($d, $command, $reconcile, &$proof, &$proofError) {
                $hash = $this->canonical->intent($command, $proof);
                $prior = DB::table('public.billing_requests')->where('actor_id', $d['actor_id'])->where('request_id', $d['request_id'])->first();
                if ($prior) {
                    if ($prior->operation !== $d['operation'] || ! hash_equals($prior->intent_hash, $hash)
                        || $prior->invoice_id !== $root->id || $prior->outcome_revision_id !== $d['revision_id']) {
                        throw new PaymentFailure('idempotency_conflict');
                    }
                    $original = $this->payment($root->id, $prior->outcome_payment_id);
                    if (! $original || $original->invoice_revision_id !== $d['revision_id']
                        || ($d['payment_id'] !== null && $original->id !== $d['payment_id'])) {
                        throw new PaymentFailure('integrity_violation');
                    }

                    return new PaymentResult($d['operation'], $root->id, $d['revision_id'], $original->id,
                        $original->amount, BillingTime::format(BillingTime::instant($prior->completed_at)), true);
                }
                if ($reconcile) {
                    throw new PaymentFailure('outcome_unresolved');
                }
                if ($proofError) {
                    throw $proofError;
                }
                if ($root->status !== 'unpaid' || $root->amount === '0.00' || $root->current_revision_id !== $d['revision_id']) {
                    throw new PaymentFailure('invoice_not_eligible');
                }
                $at = $this->clock();
                $seconds = BillingTime::instant($at)->format('Y-m-d H:i:s+00');
                if ($d['operation'] === 'submit') {
                    if (DB::table('public.payments')->where('invoice_id', $root->id)->whereIn('status', ['pending', 'verified'])->exists()) {
                        throw new PaymentFailure('pending_payment_exists');
                    }
                    $id = (string) Str::uuid();
                    DB::table('public.payments')->insert(['id' => $id, 'invoice_id' => $root->id, 'invoice_revision_id' => $root->current_revision_id,
                        'submitted_by' => $d['actor_id'], 'amount' => $root->amount, ...$proof->row(),
                        'submitted_at' => $seconds, 'status' => 'pending', 'verified_by' => null, 'verified_at' => null,
                        'verification_notes' => null, 'billing_schema_version' => 1, 'submission_channel' => 'family_upload',
                        'public_rejection_reason' => null, 'created_at' => $seconds, 'updated_at' => $seconds]);
                    $action = 'payment_submitted';
                } else {
                    if (! $row || $row->status !== 'pending' || ! hash_equals($d['expected_state'], $this->canonical->state($root, $row))) {
                        throw new PaymentFailure('stale_payment_state');
                    }
                    if ($row->invoice_revision_id !== $root->current_revision_id || $row->amount !== $root->amount) {
                        throw new PaymentFailure('invalid_payment_target');
                    }
                    $id = $row->id;
                    $verified = $d['operation'] === 'verify';
                    DB::table('public.payments')->where('id', $id)->update([
                        'status' => $verified ? 'verified' : 'rejected', 'verified_by' => $d['actor_id'], 'verified_at' => $seconds,
                        'verification_notes' => $verified ? null : $d['private_note'],
                        'public_rejection_reason' => $verified ? null : $d['public_reason'], 'updated_at' => $seconds]);
                    if ($verified) {
                        DB::table('public.invoices')->where('id', $root->id)->update(['status' => 'paid', 'paid_at' => $seconds, 'updated_at' => $seconds]);
                    }
                    $action = $verified ? 'payment_verified' : 'payment_rejected';
                }
                $request = (string) Str::uuid();
                DB::table('public.billing_requests')->insert(['id' => $request, 'actor_id' => $d['actor_id'], 'request_id' => $d['request_id'],
                    'operation' => $d['operation'], 'intent_hash' => $hash, 'invoice_id' => $root->id,
                    'outcome_payment_id' => $id, 'outcome_revision_id' => $d['revision_id'], 'completed_at' => $at]);
                $sequence = (int) DB::table('public.billing_actions')->where('invoice_id', $root->id)->max('invoice_sequence') + 1;
                DB::table('public.billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => $request,
                    'actor_id' => $d['actor_id'], 'invoice_id' => $root->id, 'ownership_period_id' => null,
                    'payment_id' => $id, 'revision_id' => $d['revision_id'], 'invoice_sequence' => $sequence,
                    'action' => $action, 'occurred_at' => $at, 'reason_code' => $d['reason_code'], 'private_note' => $d['private_note'],
                    'details' => json_encode($d['operation'] === 'verify' ? ['v' => 1, 'bank_receipt_checked' => true] : ['v' => 1], JSON_THROW_ON_ERROR)]);
                DB::select('SELECT public.billing_validate_invoice(?)', [$root->id]);

                return new PaymentResult($d['operation'], $root->id, $d['revision_id'], $id, $root->amount, $at);
            }, $prepare);
    }

    private function locked(string $actorId, string $invoiceId, ?string $paymentId, string $operation, Closure $body, ?Closure $prepare = null): mixed
    {
        $this->readiness->entry(); // No development connection is opened on the fail-closed path.
        if (! Auth::check() || Auth::id() !== $actorId) {
            throw new PaymentFailure('unauthorized');
        }
        if (DB::transactionLevel() !== 0) {
            throw new PaymentFailure('outer_transaction_required');
        }
        $committing = false;
        try {
            $observed = $this->root($invoiceId);
            $preActor = User::find($actorId);
            $prePayment = $paymentId ? $this->payment($invoiceId, $paymentId) : null;
            $this->authorize($preActor, $observed, $prePayment, $operation, $paymentId);
            $prepare?->__invoke($observed, $prePayment);
            $ids = $this->users($actorId, $observed);
            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement("SET LOCAL statement_timeout='15s'");
            DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
            $this->readiness->transaction();
            $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'role', 'is_active'])->keyBy('id');
            DB::table('public.elderlies')->where('id', $observed->elderly_id)->lockForUpdate()->first();
            $latest = $this->root($invoiceId);
            if (! $latest || $this->users($actorId, $latest) !== $ids) {
                throw new PaymentFailure('stale_owner_context');
            }
            DB::table('public.service_enrollments')->where('id', $latest->service_enrollment_id)->lockForUpdate()->first();
            DB::table('public.financial_ownership_periods')->where('elderly_id', $latest->elderly_id)->orderBy('id')->lockForUpdate()->get();
            DB::table('public.invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            DB::table('public.payments')->where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get();
            $root = $this->root($invoiceId);
            $row = $paymentId ? $this->payment($invoiceId, $paymentId) : null;
            $this->authorize($users->get($actorId), $root, $row, $operation, $paymentId);
            DB::select('SELECT public.billing_validate_invoice(?)', [$invoiceId]);
            DB::select('SELECT public.billing_validate_ownership(?)', [$root->elderly_id]);
            $result = $body($root, $row);
            $committing = true;
            $this->commit();

            return $result;
        } catch (Throwable $error) {
            $state = $error instanceof PDOException ? ($error->errorInfo[0] ?? (string) $error->getCode()) : null;
            try {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            } catch (Throwable) {
                DB::purge('silansia_testing');
                throw new PaymentFailure('unknown_commit_outcome', $state);
            }
            $knownAbort = $state !== null && (str_starts_with($state, '23') || in_array($state, ['55P03', '57014', '40001', '40P01'], true));
            if ($committing && ! $knownAbort) {
                DB::purge('silansia_testing');
                throw new PaymentFailure('unknown_commit_outcome', $state);
            }
            if ($error instanceof PaymentFailure) {
                throw $error;
            }
            if ($state && str_starts_with($state, '23')) {
                preg_match('/Integritas billing ditolak \(([a-z_]+)\)|constraint "([a-z_]+)"|(test_payment_fault)/', $error->errorInfo[2] ?? '', $match);
                throw new PaymentFailure('integrity_violation', $state, ($match[1] ?? '') ?: (($match[2] ?? '') ?: (($match[3] ?? '') ?: null)));
            }
            if (in_array($state, ['55P03', '57014', '40001', '40P01'], true)) {
                throw new PaymentFailure(match ($state) {
                    '55P03' => 'lock_timeout', '57014' => 'statement_timeout', default => 'transient_failure',
                }, $state);
            }
            throw new PaymentFailure('infrastructure_failure', $state);
        }
    }

    private function authorize(?User $actor, ?object $root, ?object $payment, string $operation, ?string $paymentId): void
    {
        if (! $actor || ! $actor->is_active || ($operation !== 'submit' && ! $this->policy->review($actor))
            || ($operation === 'submit' && $actor->role !== 'keluarga')) {
            throw new PaymentFailure('unauthorized');
        }
        if (! $root) {
            throw new PaymentFailure('target_not_found');
        }
        if ($root->billing_schema_version !== 1) {
            throw new PaymentFailure('unmanaged_invoice');
        }
        if ($operation === 'submit' && ! $this->policy->submit($actor, $root, $root->current_family_id)) {
            throw new PaymentFailure('unauthorized');
        }
        if ($paymentId !== null && (! $payment || $payment->billing_schema_version !== 1 || $payment->submission_channel !== 'family_upload')) {
            throw new PaymentFailure('invalid_payment_target');
        }
    }

    private function root(string $id): ?object
    {
        return DB::table('public.invoices as i')->join('public.elderlies as e', 'e.id', '=', 'i.elderly_id')
            ->where('i.id', $id)->select('i.*', 'e.family_id as current_family_id')->selectRaw('i.amount::text as amount')->first();
    }

    private function payment(string $invoice, ?string $id): ?object
    {
        return DB::table('public.payments')->where('invoice_id', $invoice)->where('id', $id)->select('*')->selectRaw('amount::text as amount')->first();
    }

    private function users(string $actor, object $root): array
    {
        $ids = array_unique([$actor, $root->current_family_id, $root->bill_to_family_id]);
        sort($ids, SORT_STRING);

        return array_values($ids);
    }

    private function clock(): string
    {
        return DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
    }

    protected function commit(): void
    {
        DB::commit();
    }
}
