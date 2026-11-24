<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Services\Billing\InvoiceSchemaContract;
use App\Services\Billing\PaymentCanonical;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentReadiness;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ProofScanReadiness;
use App\Services\Billing\ProofScanSchemaContract;
use App\Services\Billing\StoredPaymentProof;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

// Definitions for the owned runtime after M10/M11, before any policy fixtures.
final class PaymentScannerGraphCases
{
    use BillingIntegrityFixtures {
        billingGraph as private buildBillingGraph;
    }

    private array $observations = [];

    public function __construct(
        private readonly Closure $verifyOwned,
        private readonly Closure $record,
        private readonly bool $directVerifyMustDeny = false,
    ) {}

    /** @return list<string> Case IDs that completed, in execution order. */
    public function run(): array
    {
        $this->guard();
        $schema = app(ProofScanSchemaContract::class);
        $schema->prepareExpected();
        $schema->assertReady();
        $contract = app(InvoiceSchemaContract::class);
        $contract->assertReady();
        $pin = $contract->fingerprint();
        $this->check(! DB::table('public.proof_scan_policies')->exists()
            && ! DB::table('public.proof_scan_policy_heads')->exists(), 'empty_policy_fixture_required');

        // These synthetic gates must already have been enabled by the owned launcher.
        $paymentReadiness = app(PaymentReadiness::class);
        $paymentReadiness->entry();
        $canonical = app(PaymentCanonical::class);
        $policy = app(PaymentPolicy::class);
        $proofs = new PaymentScannerGraphFixtureStore;
        $service = new PaymentService($paymentReadiness, $proofs, $canonical, $policy);
        $completed = [];
        $case = function (string $id, callable $body) use (&$completed): void {
            $this->observations = [];
            $started = gmdate('c');
            try {
                $this->guard();
                $body();
                ($this->record)(['id' => $id, 'status' => 'PASS', 'started_at' => $started,
                    'finished_at' => gmdate('c'), 'actual' => $this->observations]);
            } catch (Throwable $error) {
                ($this->record)(['id' => $id, 'status' => 'FAIL', 'started_at' => $started,
                    'finished_at' => gmdate('c'), 'actual' => $this->observations, 'exception' => get_class($error)]);
                throw $error;
            } finally {
                Auth::forgetUser();
            }
            $completed[] = $id;
        };

        $g = $this->billingGraph();
        $originalProof = $proofs->add($reference = $this->billingId(), $g['p']['family'], $g['invoice']['id']);
        $submit = $this->command($g, $reference);
        $initial = $this->snapshot();

        $case('J1-01-no-policy', function () use ($g, $service, $submit, $initial): void {
            $this->login($g['p']['family']);
            $this->fails('infrastructure_failure', fn () => $service->submit($submit), 'P0002');
            $this->sameSnapshot($initial);
        });

        $authority = DB::scalar('SELECT public.proof_scan_authority()');
        $definition = [
            'version' => 'academic-sim-v1',
            'definition_version' => 1,
            'stage_plan' => [['stage_id' => 'synthetic_stage_v1', 'is_simulation' => true]],
            'allowed_versions' => [
                'adapter_version' => '1', 'scanner_identity' => 'synthetic_mock',
                'engine_id' => 'synthetic_mock', 'engine_version' => '1',
                'signature_set_id' => 'not_applicable_simulation', 'signature_version' => 'not_applicable_simulation',
                'auth_scheme_version' => 'trusted_in_process_v1', 'key_epoch_reference' => 'not_applicable_owned_runtime',
            ],
            'retry_profile' => ['max_attempts' => 2, 'adapter_timeout_seconds' => 5, 'lease_seconds' => 30, 'retry_due_seconds' => 1],
            'freshness_profile' => ['verdict_seconds' => 120],
            'resource_profile' => ['worker_concurrency' => 1, 'dispatcher' => 'manual_one_shot'],
        ];
        $policyId = $this->transaction(fn () => DB::scalar('SELECT public.proof_scan_create_policy(?::jsonb, ?)',
            [json_encode($definition, JSON_THROW_ON_ERROR), $authority]));
        $case('J1-02-no-head', function () use ($g, $service, $submit): void {
            $this->login($g['p']['family']);
            $before = $this->snapshot();
            $this->fails('infrastructure_failure', fn () => $service->submit($submit), 'P0002');
            $this->sameSnapshot($before);
        });

        $incarnation = $this->billingId();
        $setHead = fn (int $epoch, string $admission) => $this->transaction(fn () => DB::scalar(
            'SELECT public.proof_scan_set_head(?, ?, ?, ?, ?)', [$policyId, $incarnation, $epoch, $admission, $authority]));
        $this->check((int) $setHead(0, 'held') === 1, 'initial_held_epoch');
        $case('J1-03-held', function () use ($g, $service, $submit): void {
            $this->login($g['p']['family']);
            $before = $this->snapshot();
            $this->fails('integrity_violation', fn () => $service->submit($submit), '23514', 'pscan_admission');
            $this->sameSnapshot($before);
        });
        $this->check((int) $setHead(1, 'active') === 2, 'active_epoch');

        $case('J1-04-invalid-descriptor-and-digest', function () use ($g, $service, $proofs, $originalProof): void {
            $this->login($g['p']['family']);
            $before = $this->snapshot();
            // A valid stored tuple bound to a different UUID reaches the technical descriptor check.
            $reference = $this->billingId();
            $proofs->alias($reference, $g['p']['family'], $g['invoice']['id'], $originalProof);
            $this->fails('integrity_violation', fn () => $service->submit($this->command($g, $reference)), '23514', 'pscan_descriptor');
            $this->sameSnapshot($before);

            $reference = $this->billingId();
            $proofs->add($reference, $g['p']['family'], $g['invoice']['id'], str_repeat('1', 64));
            $this->fails('proof_integrity_failure', fn () => $service->submit($this->command($g, $reference)));
            $this->sameSnapshot($before);
        });

        $case('J1-05-exact-binding-rejection', function () use ($g, $authority, $originalProof, $reference): void {
            $other = $this->billingGraph();
            $before = $this->snapshot();
            try {
                $this->transaction(function () use ($g, $other, $authority, $originalProof, $reference): void {
                    $target = $this->insertFinancialFixture($g, $originalProof);
                    $otherReference = $this->billingId();
                    $otherProof = new StoredPaymentProof("payment-proofs/{$otherReference}/object.png",
                        $originalProof->sha256, $originalProof->size, $originalProof->mime);
                    $wrong = $this->insertFinancialFixture($other, $otherProof);
                    DB::select('SELECT public.proof_scan_initialize_subject(?::uuid, ?::uuid, ?::uuid, ?::jsonb, ?)',
                        [$target['payment'], $wrong['request'], $target['action'],
                            json_encode(['descriptor_version' => 1, 'proof_reference' => $reference, ...$originalProof->row()], JSON_THROW_ON_ERROR), $authority]);
                });
                throw new RuntimeException('initializer_must_reject_binding');
            } catch (QueryException $e) {
                $this->check(($e->errorInfo[0] ?? null) === '23514'
                    && preg_match('/\(pscan_initial_binding\)|constraint "pscan_initial_binding"/', $e->errorInfo[2] ?? '') === 1,
                    'unexpected_binding_diagnostic');
                $this->observations['rejections'][] = ['sqlstate' => $e->errorInfo[0], 'rule' => 'pscan_initial_binding'];
            }
            $this->sameSnapshot($before);
        });

        $result = null;
        $case('J1-06-atomic-commit', function () use ($g, $service, $submit, $originalProof, $incarnation, &$result): void {
            $this->login($g['p']['family']);
            $invoiceBefore = $this->row('invoices', $g['invoice']['id']);
            $backendBefore = DB::scalar('SELECT pg_backend_pid()');
            $result = $service->submit($submit);
            $this->check(! $result->replayed && $result->amount === $g['revision']['amount'], 'fresh_financial_result');
            $this->check(DB::transactionLevel() === 0, 'fresh_submit_left_transaction_open');
            DB::disconnect('silansia_testing');
            $this->guard();
            $backendAfter = DB::scalar('SELECT pg_backend_pid()');
            $this->check($backendBefore !== $backendAfter, 'durability_read_requires_new_backend');
            $this->assertGraph($submit, $result->paymentId, $originalProof, $incarnation);
            $this->check($invoiceBefore === $this->row('invoices', $result->invoiceId), 'invoice_unchanged_on_submit');
            $this->observations['invoice_unchanged'] = true;
            $this->observations['durability_read'] = ['writer_backend' => $backendBefore, 'reader_backend' => $backendAfter,
                'owned_identity_reverified' => true];
        });

        $case('J1-07-replay-before-g', function () use ($g, $service, $submit, $result, $setHead): void {
            $this->login($g['p']['family']);
            $this->check((int) $setHead(2, 'held') === 3, 'held_epoch');
            $before = $this->snapshot();
            // Force off-lock technical resolution to fail. A held head independently denies fresh admission.
            app()->bind(ProofScanReadiness::class, static fn () => throw new RuntimeException('synthetic_preparation_failure'));
            try {
                $replay = $service->submit($submit);
                $reconciled = $service->reconcile($submit);
                foreach ([$replay, $reconciled] as $fact) {
                    $this->check($fact->replayed && $fact->paymentId === $result->paymentId
                        && $fact->recordedAt === $result->recordedAt, 'canonical_fact_changed');
                }
                $this->sameSnapshot($before);
                $this->observations['canonical_replay_and_reconcile'] = true;
            } finally {
                unset(app()[ProofScanReadiness::class]);
            }
            $this->check((int) $setHead(3, 'active') === 4, 'reactivated_epoch');
        });

        $case('J1-08-conflict-and-authorization', function () use ($g, $service, $submit, $proofs): void {
            $this->login($g['p']['family']);
            $otherReference = $this->billingId();
            $proofs->add($otherReference, $g['p']['family'], $g['invoice']['id']);
            $conflict = new PaymentCommand(array_replace($submit->data, ['proof_reference' => $otherReference]));
            $before = $this->snapshot();
            $this->fails('idempotency_conflict', fn () => $service->submit($conflict));
            $this->login($g['p']['admin']);
            $this->fails('unauthorized', fn () => $service->submit($this->command($g, $otherReference, $g['p']['admin'])));
            $this->sameSnapshot($before);

            $this->transaction(function () use ($g): void {
                $this->billingLocks($g);
                $this->billingTransfer($g);
            });
            $afterTransfer = $this->snapshot();
            $this->login($g['p']['family']);
            $this->fails('unauthorized', fn () => $service->submit($submit));
            $this->login($g['p']['next_family']);
            $this->fails('unauthorized', fn () => $service->submit($this->command($g, $otherReference, $g['p']['next_family'])));
            $this->sameSnapshot($afterTransfer);
        });

        $case('J1-09-original-unknown', function () use ($paymentReadiness, $proofs, $canonical, $policy, $service, $incarnation): void {
            $g = $this->billingGraph();
            $this->login($g['p']['family']);
            $proofs->add($reference = $this->billingId(), $g['p']['family'], $g['invoice']['id']);
            $submit = $this->command($g, $reference);
            $fault = new class($paymentReadiness, $proofs, $canonical, $policy) extends PaymentService
            {
                public int $commitCalls = 0;

                public bool $persist = false;

                protected function commit(): void
                {
                    $this->commitCalls++;
                    if ($this->persist) {
                        parent::commit();
                    }
                    $e = new PDOException('Synthetic commit acknowledgement loss');
                    $e->errorInfo = ['08007', null, 'synthetic'];
                    throw $e;
                }
            };

            $before = $this->snapshot();
            $backendBefore = DB::scalar('SELECT pg_backend_pid()');
            $this->observations['not_durable'] = ['before_backend' => $backendBefore];
            $this->fails('unknown_commit_outcome', fn () => $fault->submit($submit), '08007');
            $this->check(! isset(app('db')->getConnections()['silansia_testing']), 'owned_connection_not_purged');
            $this->guard();
            $backendAfter = DB::scalar('SELECT pg_backend_pid()');
            $this->check($backendBefore !== $backendAfter, 'owned_backend_not_replaced');
            $this->check($fault->commitCalls === 1, 'automatic_commit_retry');
            $this->fails('outcome_unresolved', fn () => $service->reconcile($submit));
            $this->sameSnapshot($before);
            $this->observations['not_durable'] = ['commit_attempts' => 1, 'before_backend' => $backendBefore,
                'after_backend' => $backendAfter, 'purged' => true, 'owned_identity_reverified' => true];

            // Independent command and graph for a durable COMMIT with lost response.
            $g = $this->billingGraph();
            $this->login($g['p']['family']);
            $proof = $proofs->add($reference = $this->billingId(), $g['p']['family'], $g['invoice']['id']);
            $submit = $this->command($g, $reference);
            $fault->persist = true;
            $backendBefore = DB::scalar('SELECT pg_backend_pid()');
            $this->observations['durable'] = ['before_backend' => $backendBefore];
            $this->fails('unknown_commit_outcome', fn () => $fault->submit($submit), '08007');
            $this->check(! isset(app('db')->getConnections()['silansia_testing']), 'owned_connection_not_purged');
            $this->guard();
            $backendAfter = DB::scalar('SELECT pg_backend_pid()');
            $this->check($backendBefore !== $backendAfter, 'owned_backend_not_replaced');
            $this->check($fault->commitCalls === 2, 'automatic_commit_retry');
            $committed = $this->snapshot();
            $fact = $service->reconcile($submit);
            $this->check($fact->replayed, 'original_fact_not_recovered');
            $this->assertGraph($submit, $fact->paymentId, $proof, $incarnation);
            $this->sameSnapshot($committed);
            $this->observations['durable'] = ['commit_attempts' => 1, 'before_backend' => $backendBefore,
                'after_backend' => $backendAfter, 'purged' => true, 'owned_identity_reverified' => true, 'reconcile_replayed' => true];

            $this->login($g['p']['admin']);
            $state = $service->reviewState($g['p']['admin'], $fact->invoiceId, $fact->paymentId);
            $technicalBefore = $this->technicalSnapshot();
            $verify = new PaymentCommand([
                'operation' => 'verify', 'actor_id' => $g['p']['admin'], 'invoice_id' => $fact->invoiceId,
                'revision_id' => $fact->revisionId, 'request_id' => $this->billingId(), 'payment_id' => $fact->paymentId,
                'expected_state' => $state, 'confirm' => true, 'bank_receipt_checked' => true,
            ]);
            if ($this->directVerifyMustDeny) {
                $this->fails('proof_use_unavailable', fn () => $service->verify($verify));
                $this->check($this->row('payments', $fact->paymentId)['status'] === 'pending'
                    && $this->row('invoices', $fact->invoiceId)['status'] === 'unpaid' && $this->technicalSnapshot() === $technicalBefore,
                    'legacy_direct_verify_touched_graph');
                $this->assertProofFields($this->row('payments', $fact->paymentId), $proof);
                $this->observations['manual_verify'] = ['direct_denied' => true, 'technical_snapshot_unchanged' => true];

                return;
            }
            $service->verify($verify);
            $payment = $this->row('payments', $fact->paymentId);
            $this->assertProofFields($payment, $proof);
            $this->check($payment['status'] === 'verified' && $this->row('invoices', $fact->invoiceId)['status'] === 'paid'
                && $this->technicalSnapshot() === $technicalBefore, 'existing_verify_or_proof_changed');
            $this->observations['manual_verify'] = ['payment' => 'verified', 'invoice' => 'paid', 'technical_snapshot_unchanged' => true];
        });

        $case('J1-10-complete-graph-rollback', function () use ($paymentReadiness, $proofs, $canonical, $policy): void {
            $g = $this->billingGraph();
            $this->login($g['p']['family']);
            $proofs->add($reference = $this->billingId(), $g['p']['family'], $g['invoice']['id']);
            $submit = $this->command($g, $reference);
            $fault = new class($paymentReadiness, $proofs, $canonical, $policy) extends PaymentService
            {
                public string $invoiceId;

                protected function commit(): void
                {
                    $joinedGraph = DB::table('public.payments as p')
                        ->join('public.proof_scan_subjects as q', 'q.payment_id', '=', 'p.id')
                        ->join('public.billing_requests as r', 'r.id', '=', 'q.billing_request_id')
                        ->join('public.billing_actions as a', 'a.id', '=', 'q.billing_action_id')
                        ->join('public.proof_scan_jobs as j', 'j.subject_id', '=', 'q.id')
                        ->join('public.proof_scan_audit_events as e', 'e.subject_id', '=', 'q.id')
                        ->where('p.invoice_id', $this->invoiceId);
                    if (DB::transactionLevel() !== 1 || $joinedGraph->count() !== 2) {
                        throw new RuntimeException('graph_not_present_before_commit_attempt');
                    }
                    $e = new PDOException('Synthetic known abort after graph creation');
                    $e->errorInfo = ['23514', null, 'test_payment_fault'];
                    throw $e;
                }
            };
            $fault->invoiceId = $g['invoice']['id'];
            $before = $this->snapshot();
            $this->fails('integrity_violation', fn () => $fault->submit($submit), '23514', 'test_payment_fault');
            $this->sameSnapshot($before);
            $this->observations['graph_present_in_outer_transaction_before_abort'] = true;
        });

        $case('J1-11-financial-pin', function () use ($contract, $pin, $schema): void {
            $contract->assertReady();
            $this->check($contract->fingerprint() === $pin, 'financial_schema_drift');
            $schema->assertReady();
            $this->observations['schema'] = ['financial_fingerprint' => $pin, 'technical_assert_ready' => true];
        });

        $case('FIN-01-reject-resubmit-verify', function () use ($service, $proofs, $incarnation): void {
            $g = $this->billingGraph();
            $family = $g['p']['family'];
            $admin = $g['p']['admin'];
            $invoice = $g['invoice']['id'];
            $revision = $g['revision']['id'];
            $this->login($family);
            $proof = $proofs->add($reference = $this->billingId(), $family, $invoice);
            $command = $this->command($g, $reference);
            $first = $service->submit($command);
            $this->assertGraph($command, $first->paymentId, $proof, $incarnation);

            $this->login($admin);
            $beforeReject = $this->technicalSnapshot();
            $reject = new PaymentCommand([
                'operation' => 'reject', 'actor_id' => $admin, 'invoice_id' => $invoice, 'revision_id' => $revision,
                'request_id' => $this->billingId(), 'payment_id' => $first->paymentId,
                'expected_state' => $service->reviewState($admin, $invoice, $first->paymentId),
                'confirm' => true, 'reason_code' => 'receipt_not_confirmed', 'public_reason' => 'Bukti fixture ditolak.',
            ]);
            $service->reject($reject);
            $payment = $this->row('payments', $first->paymentId);
            $this->assertProofFields($payment, $proof);
            $this->check($payment['status'] === 'rejected' && $this->row('invoices', $invoice)['status'] === 'unpaid'
                && $this->technicalSnapshot() === $beforeReject, 'rejection_mutated_invoice_or_technical_graph');

            $this->login($family);
            $beforeReplay = $this->snapshot();
            $replay = $service->submit($command);
            $this->check($replay->replayed && $replay->paymentId === $first->paymentId && $replay->recordedAt === $first->recordedAt,
                'rejected_payment_lost_canonical_fact');
            $this->sameSnapshot($beforeReplay);

            $replacementProof = $proofs->add($reference = $this->billingId(), $family, $invoice);
            $replacement = $this->command($g, $reference);
            $second = $service->submit($replacement);
            $this->check($second->paymentId !== $first->paymentId && $second->amount === $first->amount, 'invalid_resubmission');
            $this->assertGraph($replacement, $second->paymentId, $replacementProof, $incarnation);

            $this->login($admin);
            $beforeVerify = $this->technicalSnapshot();
            $verify = new PaymentCommand([
                'operation' => 'verify', 'actor_id' => $admin, 'invoice_id' => $invoice, 'revision_id' => $revision,
                'request_id' => $this->billingId(), 'payment_id' => $second->paymentId,
                'expected_state' => $service->reviewState($admin, $invoice, $second->paymentId),
                'confirm' => true, 'bank_receipt_checked' => true,
            ]);
            if ($this->directVerifyMustDeny) {
                $this->fails('proof_use_unavailable', fn () => $service->verify($verify));
                $unpaid = $this->row('invoices', $invoice);
                $payment = $this->row('payments', $second->paymentId);
                $this->assertProofFields($payment, $replacementProof);
                $this->check($unpaid['status'] === 'unpaid' && $unpaid['bill_to_family_id'] === $family
                    && $payment['status'] === 'pending' && $this->row('payments', $first->paymentId)['status'] === 'rejected'
                    && $this->technicalSnapshot() === $beforeVerify, 'direct_verify_drifted_replacement');
                $this->observations['transition'] = ['first_payment' => 'rejected', 'replacement' => 'pending',
                    'invoice' => 'unpaid', 'direct_denied' => true, 'frozen_owner_preserved' => true];

                return;
            }
            $service->verify($verify);
            $paid = $this->row('invoices', $invoice);
            $payment = $this->row('payments', $second->paymentId);
            $this->assertProofFields($payment, $replacementProof);
            $this->check($paid['status'] === 'paid' && $paid['bill_to_family_id'] === $family
                && $payment['status'] === 'verified' && $payment['verified_at'] === $paid['paid_at']
                && $this->row('payments', $first->paymentId)['status'] === 'rejected'
                && $this->technicalSnapshot() === $beforeVerify, 'verification_regression');
            $this->observations['transition'] = ['first_payment' => 'rejected', 'replacement' => 'verified',
                'invoice' => 'paid', 'frozen_owner_preserved' => true, 'review_technical_snapshot_unchanged' => true];
        });

        return $completed;
    }

    private function guard(): void
    {
        ($this->verifyOwned)();
        FrameworkRuntimeIsolation::assertProtected();
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $this->check(DB::transactionLevel() === 0, 'outer_transaction_required');
    }

    protected function billingGraph(string $amount = '3100.00'): array
    {
        $this->guard();

        return $this->buildBillingGraph($amount);
    }

    private function transaction(callable $body): mixed
    {
        $this->guard();

        return DB::transaction(function () use ($body) {
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement("SET LOCAL statement_timeout='15s'");
            DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");

            return $body();
        }, 1);
    }

    private function insertFinancialFixture(array $g, StoredPaymentProof $proof): array
    {
        $this->billingLocks($g);
        $payment = $this->billingId();
        DB::table('public.payments')->insert([
            'id' => $payment, 'invoice_id' => $g['invoice']['id'], 'invoice_revision_id' => $g['revision']['id'],
            'submitted_by' => $g['p']['family'], 'amount' => $g['revision']['amount'], ...$proof->row(),
            'submitted_at' => '2026-11-03 00:00:00+07', 'status' => 'pending', 'billing_schema_version' => 1,
            'submission_channel' => 'family_upload',
        ]);
        $request = $this->billingRequest($g, 'submit', $g['p']['family'], $g['revision']['id'], $payment);
        $action = $this->billingAction($g, $request, 'payment_submitted', $g['revision']['id'], $payment, '2026-11-03 00:00:00.123456+07');
        DB::select('SELECT public.billing_validate_invoice(?)', [$g['invoice']['id']]);

        return ['payment' => $payment, 'request' => $request['id'], 'action' => $action['id']];
    }

    private function assertGraph(PaymentCommand $command, string $id, StoredPaymentProof $proof, string $incarnation): void
    {
        $payment = $this->row('payments', $id);
        $this->check($payment['status'] === 'pending' && $payment['submission_channel'] === 'family_upload'
            && $payment['verified_by'] === null && $payment['submitted_by'] === $command->data['actor_id'], 'pending_financial_authority');
        $this->assertProofFields($payment, $proof);
        $subjects = DB::table('public.proof_scan_subjects')->where('payment_id', $id)->get();
        $this->check($subjects->count() === 1, 'exactly_one_subject');
        $q = $subjects->first();
        $this->assertProofFields((array) $q, $proof);
        $request = $this->row('billing_requests', $q->billing_request_id);
        $action = $this->row('billing_actions', $q->billing_action_id);
        $this->check($q->state === 'QUEUED' && (int) $q->generation === 1 && (int) $q->state_version === 1
            && $q->admission_incarnation === $incarnation && $q->proof_reference === $command->data['proof_reference']
            && $q->invoice_id === $command->data['invoice_id'] && $q->revision_id === $command->data['revision_id']
            && $q->submitter_id === $command->data['actor_id'] && $request['operation'] === 'submit'
            && $request['request_id'] === $command->data['request_id'] && $request['outcome_payment_id'] === $id
            && $request['actor_id'] === $q->submitter_id && $request['invoice_id'] === $q->invoice_id
            && $request['outcome_revision_id'] === $q->revision_id && $action['action'] === 'payment_submitted'
            && $action['billing_request_id'] === $request['id'] && $action['payment_id'] === $id
            && $action['invoice_id'] === $q->invoice_id && $action['revision_id'] === $q->revision_id
            && $action['actor_id'] === $q->submitter_id, 'exact_financial_binding');
        $jobs = DB::table('public.proof_scan_jobs')->where('subject_id', $q->id)->get();
        $job = $jobs->first();
        $this->check($jobs->count() === 1 && $job->state === 'READY' && (int) $job->attempt_count === 0
            && (int) $job->fence === 1 && $job->origin === 'fresh_submit', 'initial_ready_job');
        $events = DB::table('public.proof_scan_audit_events')->where('subject_id', $q->id)->get()->keyBy('event_type');
        $this->check($events->count() === 2 && $events->has(['initial_subject_bound', 'job_created'])
            && $events['initial_subject_bound']->job_id === null && $events['job_created']->job_id === $job->id, 'typed_initial_audit');
        foreach ($events as $event) {
            $this->check($event->principal_kind === 'technical_function' && $event->principal_ref === $event->event_type
                && (int) $event->subject_state_version === 1 && $event->descriptor_digest === $q->subject_digest, 'audit_binding');
        }
        $this->observations['committed_graph'] = ['payment' => $payment['status'], 'subjects' => $subjects->count(),
            'generation' => (int) $q->generation, 'state_version' => (int) $q->state_version, 'jobs' => $jobs->count(),
            'job_state' => $job->state, 'typed_events' => $events->keys()->sort()->values()->all(),
            'exact_binding' => true, 'proof_metadata_matches' => true];
    }

    private function assertProofFields(array $row, StoredPaymentProof $proof): void
    {
        $expected = $proof->row();
        $actual = array_intersect_key($row, $expected);
        if (isset($actual['proof_size_bytes'])) {
            $actual['proof_size_bytes'] = (int) $actual['proof_size_bytes'];
        }
        ksort($actual);
        ksort($expected);
        $this->check($actual === $expected, 'original_proof_changed');
    }

    private function row(string $table, string $id): array
    {
        $row = DB::table('public.'.$table)->where('id', $id)->first();
        $this->check($row !== null, 'fixture_row_missing_'.$table);

        return (array) $row;
    }

    private function command(array $g, string $reference, ?string $actor = null): PaymentCommand
    {
        return new PaymentCommand([
            'operation' => 'submit', 'actor_id' => $actor ?? $g['p']['family'], 'invoice_id' => $g['invoice']['id'],
            'revision_id' => $g['revision']['id'], 'request_id' => $this->billingId(),
            'proof_reference' => $reference, 'confirm' => true,
        ]);
    }

    private function login(string $id): void
    {
        Auth::setUser(User::findOrFail($id));
    }

    private function snapshot(): array
    {
        return $this->fingerprints(['invoices', 'invoice_revisions', 'payments', 'billing_requests', 'billing_actions', 'financial_ownership_periods'])
            + $this->technicalSnapshot();
    }

    private function technicalSnapshot(): array
    {
        return $this->fingerprints(['proof_scan_policies', 'proof_scan_policy_heads', 'proof_scan_subjects', 'proof_scan_jobs',
            'proof_scan_attempts', 'proof_scan_verdicts', 'proof_scan_audit_events']);
    }

    private function fingerprints(array $tables): array
    {
        $values = [];
        foreach ($tables as $table) {
            $values[$table] = (array) DB::selectOne("SELECT count(*) AS rows,
                md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text) AS digest FROM public.{$table} t");
        }

        return $values;
    }

    private function sameSnapshot(array $before): void
    {
        $this->guard();
        $after = $this->snapshot();
        $this->check($after === $before, 'unexpected_financial_or_technical_mutation');
        $this->observations['snapshots'][] = ['tables' => count($before),
            'before_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'after_sha256' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'transaction_level' => DB::transactionLevel()];
    }

    private function fails(string $kind, callable $body, ?string $state = null, ?string $rule = null): void
    {
        try {
            $body();
        } catch (PaymentFailure $e) {
            $this->observations['rejections'][] = ['kind' => $e->kind, 'sqlstate' => $e->sqlState, 'rule' => $e->rule];
            $this->check($e->kind === $kind && $e->sqlState === $state && $e->rule === $rule, 'unexpected_failure_'.$e->kind);
            // Looking up DB::connection() here would recreate a purged connection before its guard.
            $connection = app('db')->getConnections()['silansia_testing'] ?? null;
            $this->check(($connection?->transactionLevel() ?? 0) === 0, 'failed_command_left_transaction_open');

            return;
        }
        throw new RuntimeException('expected_failure_'.$kind);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    }
}

// Synthetic in-memory fixture only. Paths match the approved tuple without touching private storage.
final class PaymentScannerGraphFixtureStore extends PaymentProofStore
{
    private const BYTES = 'synthetic-payment-proof-j1';

    private array $references = [];

    public function add(string $reference, string $actor, string $invoice, ?string $digest = null): StoredPaymentProof
    {
        $proof = new StoredPaymentProof("payment-proofs/{$reference}/object.png",
            $digest ?? hash('sha256', self::BYTES), strlen(self::BYTES), 'image/png');
        $this->alias($reference, $actor, $invoice, $proof);

        return $proof;
    }

    public function alias(string $reference, string $actor, string $invoice, StoredPaymentProof $proof): void
    {
        $this->references[$reference] = [$actor, $invoice, $proof];
    }

    public function resolve(string $reference, string $submitter, string $invoice): StoredPaymentProof
    {
        $entry = $this->references[$reference] ?? null;
        if ($entry === null || $entry[0] !== $submitter || $entry[1] !== $invoice) {
            throw new PaymentFailure('invalid_proof');
        }

        return $entry[2];
    }

    public function assertStored(StoredPaymentProof $proof, string $submitter, string $invoice): void
    {
        $reference = explode('/', $proof->path)[1];
        if ($this->resolve($reference, $submitter, $invoice)->row() !== $proof->row()
            || ! hash_equals(hash('sha256', self::BYTES), $proof->sha256)) {
            throw new PaymentFailure('proof_integrity_failure');
        }
    }
}
