<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

class InvoiceIssuanceService
{
    public function __construct(
        private readonly InvoiceIssuanceReadiness $readiness,
        private readonly InvoiceSchemaContract $schema,
        private readonly InvoiceSourceReader $reader,
        private readonly InvoiceCanonical $canonical,
        private readonly InvoiceEligibilityEvaluator $evaluator,
    ) {}

    public function preview(string $actorId, string $elderlyId, string $month): InvoiceIssuePreview
    {
        $command = new InvoiceIssueCommand($actorId, $elderlyId, $month, (string) Str::uuid(),
            new InvoiceIssueConfirmation(str_repeat('0', 64)));

        return $this->execute($command, preview: true);
    }

    public function issue(InvoiceIssueCommand $command): InvoiceIssueResult
    {
        return $this->execute($command);
    }

    private function execute(InvoiceIssueCommand $command, bool $preview = false, bool $reconcileOnly = false): InvoiceIssuePreview|InvoiceIssueResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvoiceIssuanceFailure('outer_transaction_required');
        }
        // Tidak ada side effect eksternal. Maksimal dua whole-transaction attempts.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $committing = false;
            try {
                $this->readiness->entry();
                $this->schema->assertReady();
                $candidateIds = $this->reader->userIds($command->actorId, $command->elderlyId);
                DB::beginTransaction();
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '15s'");
                DB::statement("SET LOCAL idle_in_transaction_session_timeout = '20s'");
                $this->readiness->transaction();
                $users = User::whereIn('id', $candidateIds)->orderBy('id')->lockForUpdate()->get(['id', 'role', 'is_active'])->keyBy('id');
                $actor = $users->get($command->actorId);
                if (! $actor) {
                    throw new InvoiceIssuanceFailure('unauthorized');
                }
                Gate::forUser($actor)->authorize('issue', Invoice::class);
                if (! $preview && ($replay = $this->replay($command)) !== null) {
                    $outcome = $replay;
                } elseif ($reconcileOnly) {
                    throw new InvoiceIssuanceFailure('unknown_commit_outcome');
                } else {
                    $resident = DB::table('public.elderlies')->where('id', $command->elderlyId)->lockForUpdate()->first(['id', 'family_id', 'is_active']);
                    if (! $resident) {
                        throw new InvoiceIssuanceFailure('invalid_command');
                    }
                    if ($candidateIds !== $this->reader->userIds($command->actorId, $command->elderlyId)) {
                        throw new InvoiceIssuanceFailure('stale_user_lock_set');
                    }
                    $snapshot = $this->reader->read($resident, $command->billingMonth);
                    if ($snapshot->existingInvoiceId !== null) {
                        throw new InvoiceIssuanceFailure('existing_root_conflict');
                    }
                    $at = $this->reader->clock();
                    $eligibility = $this->evaluator->evaluate($snapshot->eligibility($command->billingMonth, $at));
                    $ownersValid = true;
                    foreach (array_unique([$snapshot->currentFamilyId, ...array_map(fn ($p) => $p->familyId, $snapshot->periods)]) as $id) {
                        // Inactive family tidak menghapus kewajiban historis. Tidak mengubah role/akun.
                        if ($users->get($id)?->role !== 'keluarga') {
                            $ownersValid = false;
                        }
                    }
                    if (! $ownersValid && $eligibility->decision === InvoiceDecision::Issue) {
                        $eligibility = new InvoiceEligibilityResult(InvoiceDecision::Hold, InvoiceReason::InvalidChain);
                    }
                    if ($eligibility->decision === InvoiceDecision::Issue) {
                        DB::select('SELECT public.billing_validate_ownership(?)', [$command->elderlyId]);
                    }
                    $fingerprint = $this->canonical->source($snapshot, $command->billingMonth);
                    if ($preview) {
                        $outcome = new InvoiceIssuePreview(new InvoiceIssueConfirmation($fingerprint), $eligibility);
                    } else {
                        if (! hash_equals($command->confirmation->fingerprint, $fingerprint)) {
                            throw new InvoiceIssuanceFailure('stale_preview');
                        }
                        // Fresh clock dan readiness sesudah seluruh lock/validator, sebelum write.
                        $this->readiness->transaction();
                        $at = $this->reader->clock();
                        $eligibility = $this->evaluator->evaluate($snapshot->eligibility($command->billingMonth, $at));
                        if (! $ownersValid && $eligibility->decision === InvoiceDecision::Issue) {
                            $eligibility = new InvoiceEligibilityResult(InvoiceDecision::Hold, InvoiceReason::InvalidChain);
                        }
                        if ($eligibility->decision === InvoiceDecision::Issue) {
                            $outcome = $this->insertGraph($command, $snapshot, $eligibility, $at);
                        } else {
                            $outcome = new InvoiceIssueResult($eligibility->decision, $eligibility->reason);
                        }
                    }
                }
                $committing = true;
                $this->commit();

                return $outcome;
            } catch (Throwable $exception) {
                $state = $exception instanceof PDOException ? ($exception->errorInfo[0] ?? (string) $exception->getCode()) : null;
                $knownAbort = $state !== null && (str_starts_with($state, '23') || in_array($state, ['40001', '40P01', '55P03', '57014'], true));
                $unknown = $committing && ! $knownAbort;
                try {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                } catch (Throwable) {
                    // Koneksi belum membuktikan outcome. Rekonsiliasi hanya melalui key durable.
                    DB::purge('silansia_testing');
                    if (! $committing) {
                        throw new InvoiceIssuanceFailure('infrastructure_failure', $state);
                    }
                    $unknown = $committing;
                }
                if ($unknown) {
                    DB::purge('silansia_testing');
                    if ($reconcileOnly || $preview) {
                        throw new InvoiceIssuanceFailure('unknown_commit_outcome', $state);
                    }
                    try {
                        return $this->execute($command, reconcileOnly: true);
                    } catch (InvoiceIssuanceFailure $failure) {
                        if (in_array($failure->kind, ['unauthorized', 'idempotency_conflict'], true)) {
                            throw $failure;
                        }
                        throw new InvoiceIssuanceFailure('unknown_commit_outcome', $state);
                    }
                }
                if (($state !== null && in_array($state, ['40001', '40P01'], true))
                    || ($exception instanceof InvoiceIssuanceFailure && $exception->kind === 'stale_user_lock_set')) {
                    if ($attempt < 2) {
                        continue;
                    }
                    throw new InvoiceIssuanceFailure('transient_retry_exhausted', $state);
                }
                if ($exception instanceof AuthorizationException) {
                    throw new InvoiceIssuanceFailure('unauthorized');
                }
                if ($exception instanceof InvoiceIssuanceFailure) {
                    throw $exception;
                }
                if ($state !== null && str_starts_with($state, '23')) {
                    preg_match('/Integritas billing ditolak \(([a-z_]+)\)|constraint "([a-z_]+)"|(test_issue_fault)/', $exception->errorInfo[2] ?? '', $match);
                    $rule = ($match[1] ?? '') ?: (($match[2] ?? '') ?: (($match[3] ?? '') ?: null));
                    throw new InvoiceIssuanceFailure('integrity_violation', $state, $rule);
                }
                if (in_array($state, ['55P03', '57014'], true)) {
                    throw new InvoiceIssuanceFailure($state === '55P03' ? 'lock_timeout' : 'statement_timeout', $state);
                }
                throw new InvoiceIssuanceFailure('infrastructure_failure', $state);
            }
        }
        throw new InvoiceIssuanceFailure('transient_retry_exhausted');
    }

    private function replay(InvoiceIssueCommand $command): ?InvoiceIssueResult
    {
        $request = DB::table('public.billing_requests')->where('actor_id', $command->actorId)->where('request_id', $command->requestId)->first();
        if (! $request) {
            return null;
        }
        if ($request->operation !== 'issue' || ! hash_equals($request->intent_hash, $this->canonical->intent($command))) {
            throw new InvoiceIssuanceFailure('idempotency_conflict');
        }
        $root = DB::table('public.invoices')->where('id', $request->invoice_id)->first();
        $revision = DB::table('public.invoice_revisions')->where('id', $request->outcome_revision_id)->where('invoice_id', $request->invoice_id)
            ->select(['id', 'issued_at', 'revision_no', 'issued_by'])->selectRaw('amount::text AS amount')->first();
        if (! $root || ! $revision || $root->elderly_id !== $command->elderlyId || $root->billing_month !== $command->billingMonth
            || $root->billing_schema_version !== 1 || $revision->revision_no !== 1 || $revision->issued_by !== $command->actorId
            || $request->outcome_payment_id !== null) {
            throw new InvoiceIssuanceFailure('integrity_violation');
        }

        return new InvoiceIssueResult(InvoiceDecision::Issue, InvoiceReason::Eligible, $root->id, $revision->id,
            $revision->amount, BillingTime::format(BillingTime::instant($revision->issued_at)), true);
    }

    private function insertGraph(InvoiceIssueCommand $command, InvoiceSourceSnapshot $source, InvoiceEligibilityResult $eligible, string $at): InvoiceIssueResult
    {
        $calc = $eligible->calculation;
        [$start, $end] = BillingTime::month($command->billingMonth);
        $enrollment = array_values(array_filter($source->enrollments, fn ($e) => BillingTime::instant($e->startedAt) < $end
            && ($e->endedAt === null || BillingTime::instant($e->endedAt) > $start)))[0];
        $issued = BillingTime::instant($at);
        [$due, $grace] = InvoiceDueDates::fromIssuedAt($at);
        $rootId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        $requestRowId = (string) Str::uuid();
        $facts = ['elderly_id' => $command->elderlyId, 'billing_month' => $command->billingMonth,
            'service_enrollment_id' => $enrollment->id, 'financial_owner_id' => $eligible->financialOwnerId,
            'ownership_period_id' => $eligible->ownershipPeriodId, 'issued_by' => $command->actorId, 'issued_at' => $at,
            'policy_version' => $calc->policyVersion, 'monthly_price' => $calc->monthlyRate,
            'effective_started_at' => $calc->effectiveStartedAt, 'effective_ended_at' => $calc->effectiveEndedAt,
            'chargeable_days' => $calc->chargeableDays, 'days_in_month' => $calc->daysInMonth, 'amount' => $calc->amount,
            'currency' => 'IDR', 'due_date' => $due, 'grace_period_ends_on' => $grace];
        $hash = $this->canonical->content($facts);
        $seconds = $issued->format('Y-m-d H:i:s+00'); // Floor presisi legacy, jangan round melalui PostgreSQL.
        DB::table('public.invoices')->insert(['id' => $rootId, 'number' => 'SIL-'.str_replace('-', '', substr($command->billingMonth, 0, 7)).'-'.$rootId,
            'elderly_id' => $command->elderlyId, 'service_enrollment_id' => $enrollment->id, 'billing_month' => $command->billingMonth,
            'amount' => $calc->amount, 'currency' => 'IDR', 'due_date' => $due, 'grace_period_ends_on' => $grace,
            'status' => $calc->amount === '0.00' ? 'paid' : 'unpaid', 'paid_at' => $calc->amount === '0.00' ? $seconds : null,
            'created_at' => $seconds, 'updated_at' => $seconds, 'billing_schema_version' => 1,
            'bill_to_family_id' => $eligible->financialOwnerId, 'financial_ownership_period_id' => $eligible->ownershipPeriodId,
            'initial_issued_by' => $command->actorId, 'initial_issued_at' => $at, 'current_revision_id' => $revisionId,
            'cancelled_by' => null, 'cancelled_at' => null, 'cancellation_reason' => null]);
        $revision = $facts;
        unset($revision['billing_month'], $revision['financial_owner_id'], $revision['ownership_period_id']);
        DB::table('public.invoice_revisions')->insert([...$revision, 'id' => $revisionId, 'invoice_id' => $rootId,
            'revision_no' => 1, 'previous_revision_id' => null, 'reason_code' => 'initial_issue', 'reason' => null, 'content_hash' => $hash]);
        DB::table('public.billing_requests')->insert(['id' => $requestRowId, 'actor_id' => $command->actorId,
            'request_id' => $command->requestId, 'operation' => 'issue', 'intent_hash' => $this->canonical->intent($command),
            'invoice_id' => $rootId, 'outcome_payment_id' => null, 'outcome_revision_id' => $revisionId, 'completed_at' => $this->reader->clock()]);
        foreach ($calc->amount === '0.00' ? ['invoice_issued', 'zero_charge'] : ['invoice_issued'] as $index => $action) {
            DB::table('public.billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => $requestRowId,
                'actor_id' => $command->actorId, 'invoice_id' => $rootId, 'ownership_period_id' => null,
                'payment_id' => null, 'revision_id' => $revisionId, 'invoice_sequence' => $index + 1,
                'action' => $action, 'occurred_at' => $at, 'reason_code' => null, 'private_note' => null, 'details' => '{"v":1}']);
        }
        // Graph sudah lengkap. Deferred FK/trigger tetap berjalan pada outer COMMIT juga.
        DB::select('SELECT public.billing_validate_invoice(?)', [$rootId]);
        DB::select('SELECT public.billing_validate_ownership(?)', [$command->elderlyId]);

        return new InvoiceIssueResult(InvoiceDecision::Issue, InvoiceReason::Eligible, $rootId, $revisionId, $calc->amount, $at);
    }

    protected function commit(): void
    {
        DB::commit();
    }
}
