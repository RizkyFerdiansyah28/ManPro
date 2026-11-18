<?php

namespace Tests\Feature\Database;

use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StoredPaymentProof;
use App\Services\ResidentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PaymentCoreTestCase;
use Tests\Support\PaymentSqlFixture;
use Tests\Support\PostgresTestGuard;

#[Group('payment-core')]
class PaymentCoreTest extends PaymentCoreTestCase
{
    public function test_submit_reject_resubmit_verify_and_replays_preserve_command_facts(): void
    {
        $s = $this->paymentSource();
        $before = $this->fingerprints();
        [$submit,$p] = $this->pending($s);
        $this->assertSame('submission_recorded', $p->fact());
        $this->assertSame('3100.00', $p->amount);
        $this->assertSame('unpaid', DB::table('invoices')->where('id', $p->invoiceId)->value('status'));
        $this->assertSame('pending', DB::table('payments')->where('id', $p->paymentId)->value('status'));
        $reject = $this->reviewCommand($s, $p->paymentId, 'reject');
        $rejected = app(PaymentService::class)->reject($reject);
        $this->assertSame('rejection_recorded', $rejected->fact());
        $this->assertSame('Catatan privat fixture.', DB::table('payments')->where('id', $p->paymentId)->value('verification_notes'));
        $this->assertSame('Bukti belum memenuhi hasil review.', DB::table('payments')->where('id', $p->paymentId)->value('public_rejection_reason'));
        $afterReject = $this->fingerprints();
        $this->assertTrue(app(PaymentService::class)->reconcile($reject)->replayed);
        $this->unchanged($afterReject);
        $this->actingAs($s['family']);
        $replay = app(PaymentService::class)->submit($submit);
        $this->assertSame($p->paymentId, $replay->paymentId);
        $this->assertSame($p->recordedAt, $replay->recordedAt);
        $this->assertSame('submission_recorded', $replay->fact());
        $this->unchanged($afterReject);
        [$next,$p2] = $this->pending($s);
        $this->assertNotSame($p->paymentId, $p2->paymentId);
        $verify = $this->reviewCommand($s, $p2->paymentId);
        $result = app(PaymentService::class)->verify($verify);
        $root = DB::table('invoices')->where('id', $p->invoiceId)->first();
        $row = DB::table('payments')->where('id', $p2->paymentId)->first();
        $this->assertSame('paid', $root->status);
        $this->assertSame('verified', $row->status);
        $this->assertSame($root->paid_at, $row->verified_at);
        $this->assertSame($s['family']->id, $root->bill_to_family_id);
        $this->assertSame(2, DB::table('payments')->where('invoice_id', $root->id)->count());
        $this->assertSame('rejected', DB::table('payments')->where('id', $p->paymentId)->value('status'));
        $paid = $this->fingerprints();
        $this->assertTrue(app(PaymentService::class)->verify($verify)->replayed);
        $this->assertTrue(app(PaymentService::class)->reconcile($verify)->replayed);
        $this->unchanged($paid);
        $this->actingAs($s['family']);
        $this->assertSame($p2->paymentId, app(PaymentService::class)->reconcile($next)->paymentId);
        $this->unchanged($paid);
        $this->unchanged($before, ['invoices', 'payments', 'billing_requests', 'billing_actions']);
        DB::select('SELECT public.billing_validate_invoice(?)', [$root->id]);
    }

    public static function badSubmit(): array
    {
        return [['guest', 'unauthorized'], ['caregiver', 'unauthorized'], ['admin', 'unauthorized'], ['inactive', 'unauthorized'],
            ['foreign', 'unauthorized'], ['current_owner', 'unauthorized'], ['wrong_session_actor', 'unauthorized'],
            ['invoice_uuid', 'invalid_command'], ['invoice_absent', 'target_not_found'], ['revision', 'invoice_not_eligible'],
            ['amount', 'invalid_command'], ['raw_proof', 'invalid_command'], ['forged_reference', 'invalid_proof'],
            ['forged_descriptor', 'invalid_command'], ['missing_file', 'proof_integrity_failure'],
            ['default_store', 'proof_boundary_unavailable'], ['zero', 'invoice_not_eligible'], ['legacy', 'unmanaged_invoice'], ['cancelled', 'invoice_not_eligible'], ['foreign_proof', 'invalid_proof']];
    }

    #[DataProvider('badSubmit')]
    public function test_each_negative_submit_starts_valid_and_leaves_every_table_unchanged(string $case, string $kind): void
    {
        $s = $this->paymentSource($case === 'zero' ? ['price' => '0.00'] : []);
        if ($case === 'cancelled') {
            PaymentSqlFixture::mutate($s['issue']->invoiceId, $s['admin']->id, true);
        }
        $data = $this->submitCommand($s)->data;
        $actor = $s['family'];
        if ($case === 'caregiver') {
            $actor = $this->account('perawat');
        }
        if ($case === 'admin') {
            $actor = $s['admin'];
        }
        if ($case === 'foreign') {
            $actor = $s['next'];
        }
        if ($case === 'inactive') {
            $actor->forceFill(['is_active' => false])->save();
            $actor = $actor->fresh();
        }
        if ($case === 'current_owner') {
            app(ResidentService::class)->reassignFamily($s['admin'], $s['elderly'], ['family_id' => $s['next']->id, 'current_family_id' => $s['family']->id, 'confirm_reassignment' => true]);
        }
        if ($case === 'legacy') {
            $id = (string) Str::uuid();
            DB::table('invoices')->insert(['id' => $id, 'number' => 'legacy-'.$id, 'elderly_id' => $s['elderly']->id,
                'service_enrollment_id' => $s['enrollment']->id, 'billing_month' => '2026-09-01', 'amount' => '1.00',
                'currency' => 'IDR', 'due_date' => '2026-11-20', 'grace_period_ends_on' => '2026-11-23', 'status' => 'unpaid']);
            $data['invoice_id'] = $id;
        }
        if (in_array($case, ['caregiver', 'admin', 'foreign'], true)) {
            $data['actor_id'] = $actor->id;
        }
        $this->actingAs($actor);
        if ($case === 'guest') {
            Auth::logout();
            $this->app['auth']->forgetGuards();
        }
        if ($case === 'wrong_session_actor') {
            $data['actor_id'] = $s['next']->id;
        }
        if ($case === 'invoice_uuid') {
            $data['invoice_id'] = 'bad';
        }
        if ($case === 'invoice_absent') {
            $data['invoice_id'] = (string) Str::uuid();
        }
        if ($case === 'revision') {
            $data['revision_id'] = (string) Str::uuid();
        }
        if ($case === 'amount') {
            $data['amount'] = '1.00';
        }
        if ($case === 'raw_proof') {
            $data['proof_path'] = 'private/fake.png';
        }
        if ($case === 'forged_reference') {
            $data['proof_reference'] = (string) Str::uuid();
        }
        if ($case === 'foreign_proof') {
            $foreign = $this->paymentSource();
            $data['proof_reference'] = $foreign['reference'];
        }
        if ($case === 'forged_descriptor') {
            $data['proof_reference'] = new StoredPaymentProof('fake.png', str_repeat('b', 64), 128, 'image/png');
        }
        if ($case === 'missing_file') {
            $proof = $this->proofStore->resolve($s['reference'], $s['family']->id, $s['issue']->invoiceId);
            $file = dirname(PostgresTestGuard::fromEnvironment()->identity['cache']).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $proof->path);
            file_put_contents($file, 'invalid synthetic bytes');
        }
        if ($case === 'default_store') {
            $this->app->instance(PaymentProofStore::class, new PaymentProofStore);
        }
        $before = $this->fingerprints();
        $this->paymentFailure($kind, fn () => app(PaymentService::class)->submit(new PaymentCommand($data)));
        $this->unchanged($before);
    }

    public static function badReview(): array
    {
        return [['role', 'unauthorized'], ['inactive', 'unauthorized'], ['guest', 'unauthorized'], ['actor_mismatch', 'unauthorized'],
            ['parent', 'invalid_payment_target'], ['revision', 'invoice_not_eligible'], ['stale', 'stale_payment_state'],
            ['bank', 'bank_confirmation_required'], ['confirm', 'invalid_command'], ['bad_reason', 'invalid_rejection'],
            ['missing_proof', 'proof_integrity_failure'], ['assisted', 'invalid_payment_target'],
            ['foreign_parent', 'invalid_payment_target'], ['bank_missing', 'bank_confirmation_required']];
    }

    #[DataProvider('badReview')]
    public function test_negative_review_is_isolated_and_never_marks_paid(string $case, string $kind): void
    {
        $s = $this->paymentSource();
        [,$p] = $this->pending($s);
        $command = $this->reviewCommand($s, $p->paymentId, $case === 'bad_reason' ? 'reject' : 'verify');
        $d = $command->data;
        if ($case === 'foreign_parent') {
            $other = $this->paymentSource();
            [, $foreign] = $this->pending($other);
            $d['payment_id'] = $foreign->paymentId;
            $this->actingAs($s['admin']);
        }
        if ($case === 'bank_missing') {
            unset($d['bank_receipt_checked']);
        }
        if ($case === 'role') {
            $s['admin']->forceFill(['role' => 'perawat'])->save();
        }
        if ($case === 'inactive') {
            $s['admin']->forceFill(['is_active' => false])->save();
        }
        if ($case === 'guest') {
            Auth::logout();
            $this->app['auth']->forgetGuards();
        }
        if ($case === 'actor_mismatch') {
            $d['actor_id'] = $s['next']->id;
        }
        if ($case === 'parent') {
            $d['payment_id'] = (string) Str::uuid();
        }
        if ($case === 'revision') {
            $d['revision_id'] = (string) Str::uuid();
        }
        if ($case === 'stale') {
            $d['expected_state'] = str_repeat('0', 64);
        }
        if ($case === 'bank') {
            $d['bank_receipt_checked'] = false;
        }
        if ($case === 'confirm') {
            $d['confirm'] = false;
        }
        if ($case === 'bad_reason') {
            $d['public_reason'] = ' ';
        }
        if ($case === 'missing_proof') {
            $proof = $this->proofStore->resolve($s['reference'], $s['family']->id, $s['issue']->invoiceId);
            $file = dirname(PostgresTestGuard::fromEnvironment()->identity['cache']).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $proof->path);
            file_put_contents($file, 'invalid synthetic bytes');
        }
        if ($case === 'assisted') {
            // Legitimate schema fixture; not an operational assisted command.
            $other = $this->account();
            $g = $this->billingGraph();
            $g = DB::transaction(function () use ($g, $s) {
                $p = ['id' => $this->billingId(), 'invoice_id' => $g['invoice']['id'], 'submitted_by' => $s['admin']->id, 'amount' => '3100.00',
                    ...$this->proofStore->resolve($s['reference'], $s['family']->id, $s['issue']->invoiceId)->row(), 'submitted_at' => '2026-11-03 00:00:00+07', 'status' => 'pending',
                    'billing_schema_version' => 1, 'invoice_revision_id' => $g['revision']['id'], 'submission_channel' => 'admin_assisted'];
                DB::table('payments')->insert($p);
                $q = $this->billingRequest($g, 'assist_submit', $s['admin']->id, $g['revision']['id'], $p['id']);
                $this->billingAction($g, $q, 'payment_submitted', $g['revision']['id'], $p['id'], '2026-11-03 00:00:00+07');

                return $g + ['payment' => $p];
            });
            $this->actingAs($other);
            $d['actor_id'] = $other->id;
            $d['invoice_id'] = $g['invoice']['id'];
            $d['revision_id'] = $g['revision']['id'];
            $d['payment_id'] = $g['payment']['id'];
        }
        $before = $this->fingerprints();
        $this->paymentFailure($kind, fn () => app(PaymentService::class)->{$d['operation']}(new PaymentCommand($d)));
        $this->unchanged($before);
    }

    public function test_duplicate_keys_pending_conflict_and_issuance_namespace_collision(): void
    {
        $s = $this->paymentSource();
        [$submit,$p] = $this->pending($s);
        $before = $this->fingerprints();
        $this->paymentFailure('pending_payment_exists', fn () => app(PaymentService::class)->submit($this->submitCommand($s)));
        $this->paymentFailure('idempotency_conflict', fn () => app(PaymentService::class)->submit(
            $this->submitCommand($s, ['request_id' => $submit->data['request_id'], 'proof_reference' => $this->proofStore->issue($s['family']->id, $s['issue']->invoiceId)])));
        $this->unchanged($before);
        $verify = $this->reviewCommand($s, $p->paymentId, 'verify', ['request_id' => $s['issue_command']->requestId]);
        $this->paymentFailure('idempotency_conflict', fn () => app(PaymentService::class)->verify($verify));
        $this->unchanged($before);
        $reject = $this->reviewCommand($s, $p->paymentId, 'reject');
        app(PaymentService::class)->reject($reject);
        $after = $this->fingerprints();
        $changed = new PaymentCommand(array_replace($reject->data, ['operation' => 'verify', 'bank_receipt_checked' => true,
            'reason_code' => null, 'public_reason' => null, 'private_note' => null]));
        $this->paymentFailure('idempotency_conflict', fn () => app(PaymentService::class)->verify($changed));
        $this->unchanged($after);
    }

    public function test_absent_reconcile_never_calls_initial_mutation_and_replay_reauthorizes(): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        $before = $this->fingerprints();
        $this->paymentFailure('outcome_unresolved', fn () => app(PaymentService::class)->reconcile($command));
        $this->unchanged($before);
        $p = app(PaymentService::class)->submit($command);
        app(ResidentService::class)->reassignFamily($s['admin'], $s['elderly'], ['family_id' => $s['next']->id, 'current_family_id' => $s['family']->id, 'confirm_reassignment' => true]);
        $after = $this->fingerprints();
        $this->paymentFailure('unauthorized', fn () => app(PaymentService::class)->reconcile($command));
        $this->unchanged($after);
        $verify = $this->reviewCommand($s, $p->paymentId);
        app(PaymentService::class)->verify($verify);
        $this->assertSame($s['family']->id, DB::table('invoices')->where('id', $p->invoiceId)->value('bill_to_family_id'));
        $s['admin']->forceFill(['is_active' => false])->save();
        $paid = $this->fingerprints();
        $this->paymentFailure('unauthorized', fn () => app(PaymentService::class)->reconcile($verify));
        $this->unchanged($paid);
    }

    public static function failureStages(): array
    {
        $cases = [];
        foreach (['submit', 'verify', 'reject'] as $operation) {
            foreach (['payment', 'request', 'action', 'deferred'] as $stage) {
                $cases[] = [$operation, $stage];
            }
        }

        return $cases;
    }

    #[DataProvider('failureStages')]
    public function test_injected_sql_failure_rolls_back_every_financial_write(string $operation, string $stage): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        if ($operation !== 'submit') {
            [, $pending] = $this->pending($s);
            $command = $this->reviewCommand($s, $pending->paymentId, $operation);
        }
        $this->paymentFault($stage, $operation);
        $before = $this->fingerprints();
        $this->paymentFailure('integrity_violation', fn () => app(PaymentService::class)->{$operation}($command), '23514', 'test_payment_fault');
        $this->unchanged($before);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function operations(): array
    {
        return [['submit'], ['verify'], ['reject']];
    }

    #[DataProvider('operations')]
    public function test_commit_acknowledgement_loss_is_unknown_then_explicit_reconcile_returns_durable_result(string $operation): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        if ($operation !== 'submit') {
            [, $pending] = $this->pending($s);
            $command = $this->reviewCommand($s, $pending->paymentId, $operation);
        }
        $service = $this->app->makeWith(PaymentAckLossService::class);
        $this->paymentFailure('unknown_commit_outcome', fn () => $service->{$operation}($command), '08007');
        $after = $this->fingerprints();
        $replay = app(PaymentService::class)->reconcile($command);
        $this->assertTrue($replay->replayed);
        $this->assertSame(match ($operation) {
            'submit' => 'submission_recorded', 'verify' => 'verification_recorded', 'reject' => 'rejection_recorded',
        }, $replay->fact());
        $this->unchanged($after);
    }

    #[DataProvider('operations')]
    public function test_precommit_failure_is_not_a_durable_success(string $operation): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        if ($operation !== 'submit') {
            [, $pending] = $this->pending($s);
            $command = $this->reviewCommand($s, $pending->paymentId, $operation);
        }
        $before = $this->fingerprints();
        $this->paymentFailure('integrity_violation', fn () => $this->app->makeWith(PaymentPrecommitService::class)->{$operation}($command), '23514', 'test_payment_fault');
        $this->unchanged($before);
    }

    #[DataProvider('operations')]
    public function test_an_unexpected_domain_exception_after_commit_cannot_misreport_a_known_abort(string $operation): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        if ($operation !== 'submit') {
            [, $pending] = $this->pending($s);
            $command = $this->reviewCommand($s, $pending->paymentId, $operation);
        }
        $this->paymentFailure('unknown_commit_outcome', fn () => $this->app->makeWith(PaymentAfterCommitFailureService::class)->{$operation}($command));
        $after = $this->fingerprints();
        $this->assertTrue(app(PaymentService::class)->reconcile($command)->replayed);
        $this->unchanged($after);
    }

    public function test_maximum_money_is_exact_and_master_price_does_not_reprice_payment(): void
    {
        $s = $this->paymentSource(['price' => '999999999999.99']);
        DB::table('service_classes')->where('id', $s['enrollment']->service_class_id)->update(['monthly_price' => '9000.00']);
        $before = $this->fingerprints();
        [, $p] = $this->pending($s);
        $verify = $this->reviewCommand($s, $p->paymentId);
        $result = app(PaymentService::class)->verify($verify);
        $this->assertSame('999999999999.99', $result->amount);
        $this->assertSame('999999999999.99', DB::table('payments')->where('id', $p->paymentId)->value('amount'));
        $this->unchanged($before, ['invoices', 'payments', 'billing_requests', 'billing_actions']);
    }

    public function test_committed_replay_is_not_confused_with_fresh_file_validation(): void
    {
        $s = $this->paymentSource();
        [$command, $p] = $this->pending($s);
        $proof = $this->proofStore->resolve($s['reference'], $s['family']->id, $s['issue']->invoiceId);
        $file = dirname(PostgresTestGuard::fromEnvironment()->identity['cache']).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $proof->path);
        file_put_contents($file, 'corrupt synthetic file after durable commit');
        $before = $this->fingerprints();
        $this->assertSame($p->paymentId, app(PaymentService::class)->reconcile($command)->paymentId);
        $this->assertTrue(app(PaymentService::class)->submit($command)->replayed);
        $this->unchanged($before);
    }

    public function test_readiness_closes_default_wrong_connection_and_stale_cache_before_domain_mutation(): void
    {
        $s = $this->paymentSource();
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);
        $before = $this->fingerprints();
        config(['payment_core.enabled' => false]);
        $this->paymentFailure('gate_closed', fn () => app(PaymentService::class)->submit($command));
        config(['payment_core.enabled' => true]);
        config(['database.default' => 'pgsql']);
        try {
            $this->paymentFailure('owned_test_database_required', fn () => app(PaymentService::class)->submit($command));
        } finally {
            config(['database.default' => 'silansia_testing']);
        }
        $cache = PostgresTestGuard::fromEnvironment()->identity['cache'];
        file_put_contents($cache, '<?php throw new RuntimeException("must never execute");');
        try {
            $this->paymentFailure('owned_test_database_required', fn () => app(PaymentService::class)->submit($command));
        } finally {
            unlink($cache);
        }
        $this->unchanged($before);
    }
}

class PaymentAckLossService extends PaymentService
{
    protected function commit(): void
    {
        parent::commit();
        $e = new \PDOException('Synthetic acknowledgement loss', '08007');
        $e->errorInfo = ['08007', null, 'synthetic'];
        throw $e;
    }
}

class PaymentPrecommitService extends PaymentService
{
    protected function commit(): void
    {
        $error = new \PDOException('Synthetic known precommit abort', '23514');
        $error->errorInfo = ['23514', null, 'test_payment_fault'];
        throw $error;
    }
}

class PaymentAfterCommitFailureService extends PaymentService
{
    protected function commit(): void
    {
        parent::commit();
        throw new PaymentFailure('unexpected_callback_failure');
    }
}
