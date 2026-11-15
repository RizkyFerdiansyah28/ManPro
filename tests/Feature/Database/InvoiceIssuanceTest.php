<?php

namespace Tests\Feature\Database;

use App\Services\Billing\BillingTime;
use App\Services\Billing\InvoiceCanonical;
use App\Services\Billing\InvoiceDecision;
use App\Services\Billing\InvoiceEligibilityEvaluator;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use App\Services\Billing\InvoiceReason;
use App\Services\Billing\InvoiceSchemaContract;
use App\Services\Billing\InvoiceSourceReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceIssuanceFeatureTestCase;

#[Group('invoice-issuance')]
class InvoiceIssuanceTest extends InvoiceIssuanceFeatureTestCase
{
    public function test_schema_contract_matches_pristine_migrations(): void
    {
        $hash = app(InvoiceSchemaContract::class)->fingerprint();
        $this->assertSame('17c0f079263ed8fc89faf09acc661c52beee1447c9c75f2c1c1ea12e861048c0', $hash);
        $this->assertSame('read committed', DB::scalar("SELECT current_setting('transaction_isolation')"));
        app(InvoiceSchemaContract::class)->assertReady();
    }

    public function test_positive_initial_graph_commits_and_preserves_all_other_data(): void
    {
        $s = $this->source(['end' => '2026-10-20 15:00:00+07']);
        $command = $this->command($s);
        $before = $this->fingerprints();
        $existing = [];
        foreach (['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions'] as $table) {
            $existing[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $result = app(InvoiceIssuanceService::class)->issue($command);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(InvoiceDecision::Issue, $result->decision);
        $this->assertSame('2000.00', $result->amount);
        $this->assertFalse($result->replayed);
        $root = DB::table('invoices')->where('id', $result->invoiceId)->sole();
        $r = DB::table('invoice_revisions')->where('id', $result->revisionId)->sole();
        $q = DB::table('billing_requests')->where('actor_id', $command->actorId)->where('request_id', $command->requestId)->sole();
        $actions = DB::table('billing_actions')->where('invoice_id', $root->id)->orderBy('invoice_sequence')->get();
        $this->assertSame(1, $root->billing_schema_version);
        $this->assertSame('unpaid', $root->status);
        $this->assertNull($root->paid_at);
        $this->assertSame($r->id, $root->current_revision_id);
        $this->assertSame($s['family']->id, $root->bill_to_family_id);
        $this->assertSame($s['period']['id'], $root->financial_ownership_period_id);
        $this->assertSame($s['enrollment']->id, $root->service_enrollment_id);
        $this->assertSame('SIL-202610-'.$root->id, $root->number);
        $this->assertSame($s['admin']->id, $root->initial_issued_by);
        $this->assertSame($root->initial_issued_at, $r->issued_at);
        $this->assertSame($result->issuedAt, BillingTime::format(BillingTime::instant($r->issued_at)));
        foreach (['amount', 'currency', 'due_date', 'grace_period_ends_on'] as $field) {
            $this->assertSame($r->$field, $root->$field);
        }
        $this->assertSame(1, $r->revision_no);
        $this->assertNull($r->previous_revision_id);
        $this->assertNull($r->reason);
        $this->assertSame('initial_issue', $r->reason_code);
        $this->assertSame('3100.00', $r->monthly_price);
        $this->assertSame(20, $r->chargeable_days);
        $this->assertSame(31, $r->days_in_month);
        $this->assertTrue(DB::scalar("SELECT ?::date=(?::timestamptz AT TIME ZONE 'Asia/Jakarta')::date+7", [$r->due_date, $r->issued_at]));
        $this->assertTrue(DB::scalar('SELECT ?::date=?::date+3', [$r->grace_period_ends_on, $r->due_date]));
        $facts = [...(array) $r, 'billing_month' => $root->billing_month, 'financial_owner_id' => $root->bill_to_family_id,
            'ownership_period_id' => $root->financial_ownership_period_id];
        $this->assertSame(app(InvoiceCanonical::class)->content($facts), $r->content_hash);
        $this->assertSame($result->invoiceId, $q->invoice_id);
        $this->assertSame($result->revisionId, $q->outcome_revision_id);
        $this->assertSame('issue', $q->operation);
        $this->assertNull($q->outcome_payment_id);
        $this->assertSame(app(InvoiceCanonical::class)->intent($command), $q->intent_hash);
        $this->assertGreaterThanOrEqual(BillingTime::instant($r->issued_at), BillingTime::instant($q->completed_at));
        $this->assertSame(['invoice_issued'], $actions->pluck('action')->all());
        $this->assertSame([1], $actions->pluck('invoice_sequence')->all());
        $this->assertSame('{"v": 1}', $actions->sole()->details);
        $this->assertSame($q->id, $actions->sole()->billing_request_id);
        $this->assertSame($r->id, $actions->sole()->revision_id);
        $this->assertSame($q->actor_id, $actions->sole()->actor_id);
        DB::select('SELECT public.billing_validate_invoice(?)', [$root->id]);
        DB::select('SELECT public.billing_validate_ownership(?)', [$s['elderly']->id]);
        $after = $this->fingerprints();
        foreach ($before as $table => $fingerprint) {
            if (! array_key_exists($table, $existing)) {
                $this->assertEquals($fingerprint, $after[$table], $table.' wajib identik.');
            } else {
                $this->assertEquals($existing[$table], DB::table($table)->whereIn('id', array_column($existing[$table], 'id'))->orderBy('id')->get()->all());
            }
        }
        $this->assertFalse(DB::table('elderly_stays')->where('elderly_id', $s['elderly']->id)->exists());
    }

    public static function zeroRates(): array
    {
        return [['0.00', '2026-10-01 00:00:00+07', null], ['0.01', '2026-10-31 23:59:59+07', '2026-11-01 00:00:00+07']];
    }

    #[DataProvider('zeroRates')]
    public function test_zero_charge_uses_two_audits_no_payment_and_server_floor_second(string $price, string $start, ?string $end): void
    {
        $s = $this->source(compact('price', 'start', 'end'));
        $beforePayments = $this->fingerprints(['payments']);
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $root = DB::table('invoices')->where('id', $result->invoiceId)->sole();
        $this->assertSame('0.00', $result->amount);
        $this->assertSame('paid', $root->status);
        $this->assertTrue(DB::scalar("SELECT ?::timestamptz=date_trunc('second',?::timestamptz)", [$root->paid_at, $root->initial_issued_at]));
        $this->assertSame(['invoice_issued', 'zero_charge'], DB::table('billing_actions')->where('invoice_id', $root->id)->orderBy('invoice_sequence')->pluck('action')->all());
        $this->assertEquals($beforePayments, $this->fingerprints(['payments']));
    }

    public function test_real_postgres_numeric_and_microseconds_survive_mapper_evaluator_and_inactive_historical_owner_issue(): void
    {
        $s = $this->source(['price' => '999999999999.99', 'end' => '2026-11-01 00:00:00+07', 'active' => false]);
        $this->sourceTransfer($s, '2026-11-01 00:00:00.654321+07');
        $resident = DB::table('elderlies')->where('id', $s['elderly']->id)->sole();
        $input = DB::transaction(fn () => app(InvoiceSourceReader::class)->read($resident, '2026-10-01'));
        $this->assertSame('999999999999.99', $input->enrollments[0]->monthlyPrice);
        $this->assertIsString($input->enrollments[0]->monthlyPrice);
        $this->assertNotNull($input->enrollments[0]->endedAt);
        $period = array_values(array_filter($input->periods, fn ($p) => $p->id === $s['period']['id']))[0];
        $this->assertSame('2026-08-31T17:00:00.123456Z', BillingTime::format(BillingTime::instant($period->effectiveFrom)));
        $this->assertSame('2026-10-31T17:00:00.654321Z', BillingTime::format(BillingTime::instant($period->effectiveTo)));
        $this->assertSame($s['next']->id, $input->currentFamilyId);
        $eligible = app(InvoiceEligibilityEvaluator::class)->evaluate($input->eligibility('2026-10-01', app(InvoiceSourceReader::class)->clock()));
        $this->assertSame(InvoiceDecision::Issue, $eligible->decision);
        $this->assertSame($s['family']->id, $eligible->financialOwnerId);
        $this->assertSame('999999999999.99', $eligible->calculation->amount);
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame('999999999999.99', $result->amount);
        $this->assertSame($s['family']->id, DB::table('invoices')->where('id', $result->invoiceId)->value('bill_to_family_id'));
        $this->assertFalse($s['elderly']->fresh()->is_active);
    }

    public static function heldSources(): array
    {
        return [
            'null' => [['price' => null], InvoiceDecision::Hold, InvoiceReason::NullRate],
            'no service' => [['no_service' => true], InvoiceDecision::Skip, InvoiceReason::NoService],
            'empty ledger' => [['no_ledger' => true], InvoiceDecision::Hold, InvoiceReason::MissingLedger],
            'microsecond gap' => [['from' => '2026-10-01 00:00:00.000001+07'], InvoiceDecision::Hold, InvoiceReason::PartialCoverage],
        ];
    }

    #[DataProvider('heldSources')]
    public function test_hold_or_skip_never_writes_or_estimates(array $changes, InvoiceDecision $decision, InvoiceReason $reason): void
    {
        $s = $this->source($changes);
        $command = $this->command($s);
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($command);
        $this->assertSame($decision, $result->decision);
        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->amount);
        $this->assertNull($result->invoiceId);
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_mixed_owner_whole_month_even_after_closed_enrollment_is_held(): void
    {
        $s = $this->source(['end' => '2026-10-05 00:00:00+07']);
        $this->sourceTransfer($s, '2026-10-20 00:00:00.123456+07');
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame(InvoiceDecision::Hold, $result->decision);
        $this->assertSame(InvoiceReason::MixedOwners, $result->reason);
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_multiple_closed_enrollments_are_held_without_selecting_an_estimate(): void
    {
        $s = $this->source(['end' => '2026-10-10 00:00:00+07']);
        DB::table('service_enrollments')->insert(['id' => (string) Str::uuid(), 'elderly_id' => $s['elderly']->id,
            'service_class_id' => $s['enrollment']->service_class_id, 'monthly_price' => '99.00',
            'started_at' => '2026-10-10 00:00:00+07', 'ended_at' => '2026-10-20 00:00:00+07']);
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame(InvoiceReason::MultipleEnrollments, $result->reason);
        $this->assertNull($result->amount);
        $this->assertEquals($before, $this->fingerprints());
    }

    public static function invalidActors(): array
    {
        return [['admin', false], ['perawat', true], ['keluarga', true], ['missing', true]];
    }

    #[DataProvider('invalidActors')]
    public function test_actor_is_reloaded_and_authorized_before_returning_financial_outcomes(string $role, bool $active): void
    {
        $s = $this->source();
        $command = $this->command($s);
        if ($role === 'missing') {
            $command = new InvoiceIssueCommand((string) Str::uuid(), $command->elderlyId, $command->billingMonth, $command->requestId, $command->confirmation);
        } else {
            DB::table('users')->where('id', $s['admin']->id)->update(['role' => $role, 'is_active' => $active]);
        }
        $before = $this->fingerprints();
        $this->assertFailure('unauthorized', fn () => app(InvoiceIssuanceService::class)->issue($command));
        $this->assertEquals($before, $this->fingerprints());
    }

    public static function gates(): array
    {
        return [['invoice_issuance.enabled', false], ['financial_ownership.enabled', false], ['invoice_issuance.enabled', 'true']];
    }

    #[DataProvider('gates')]
    public function test_gates_fail_closed_and_never_fall_back_to_legacy(string $key, mixed $value): void
    {
        $s = $this->source();
        $command = $this->command($s);
        config([$key => $value]);
        $before = $this->fingerprints();
        $this->assertFailure('gate_closed', fn () => app(InvoiceIssuanceService::class)->issue($command));
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_readiness_refuses_nested_transactions_and_wrong_isolation(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        DB::beginTransaction();
        $this->assertFailure('outer_transaction_required', fn () => app(InvoiceIssuanceService::class)->issue($command));
        DB::rollBack();
        DB::statement("SET default_transaction_isolation='repeatable read'");
        try {
            $this->assertFailure('read_committed_required', fn () => app(InvoiceIssuanceService::class)->issue($command));
        } finally {
            DB::statement("SET default_transaction_isolation='read committed'");
        }
        $this->assertFalse(DB::table('invoices')->where('elderly_id', $s['elderly']->id)->exists());
    }

    public function test_changed_source_requires_a_new_preview_and_does_not_reprice_from_master(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        DB::table('service_classes')->where('id', $s['enrollment']->service_class_id)->update(['monthly_price' => '9000.00']);
        $result = app(InvoiceIssuanceService::class)->issue($command);
        $this->assertSame('3100.00', $result->amount);
        $other = $this->source();
        $stale = $this->command($other);
        DB::table('service_enrollments')->where('id', $other['enrollment']->id)->update(['monthly_price' => '3200.00']);
        $before = $this->fingerprints();
        $this->assertFailure('stale_preview', fn () => app(InvoiceIssuanceService::class)->issue($stale));
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_durable_replay_and_conflicts_are_scoped_to_actor_and_request(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $result = app(InvoiceIssuanceService::class)->issue($command);
        $before = $this->fingerprints();
        // Respons pertama dianggap hilang. Instance baru tidak memakai cache in-memory.
        $replay = app()->make(InvoiceIssuanceService::class)->issue($command);
        $this->assertTrue($replay->replayed);
        foreach (['invoiceId', 'revisionId', 'amount', 'issuedAt'] as $field) {
            $this->assertSame($result->$field, $replay->$field);
        }
        $this->assertEquals($before, $this->fingerprints());
        $differentIntent = new InvoiceIssueCommand($command->actorId, $command->elderlyId, $command->billingMonth, $command->requestId,
            new InvoiceIssueConfirmation(str_repeat('a', 64)));
        $this->assertFailure('idempotency_conflict', fn () => app(InvoiceIssuanceService::class)->issue($differentIntent));
        $other = $this->source();
        $differentTarget = new InvoiceIssueCommand($command->actorId, $other['elderly']->id, $command->billingMonth, $command->requestId, $command->confirmation);
        $this->assertFailure('idempotency_conflict', fn () => app(InvoiceIssuanceService::class)->issue($differentTarget));
        $newRequest = new InvoiceIssueCommand($command->actorId, $command->elderlyId, $command->billingMonth, (string) Str::uuid(), $command->confirmation);
        $this->assertFailure('existing_root_conflict', fn () => app(InvoiceIssuanceService::class)->issue($newRequest));
        $otherActor = new InvoiceIssueCommand($other['admin']->id, $command->elderlyId, $command->billingMonth, $command->requestId, $command->confirmation);
        $this->assertFailure('existing_root_conflict', fn () => app(InvoiceIssuanceService::class)->issue($otherActor));
        DB::table('users')->where('id', $command->actorId)->update(['is_active' => false]);
        $this->assertFailure('unauthorized', fn () => app(InvoiceIssuanceService::class)->issue($command));
    }

    public static function faultStages(): array
    {
        return array_map(fn ($s) => [$s], ['root', 'revision', 'request', 'issued', 'zero', 'before_commit', 'deferred']);
    }

    #[DataProvider('faultStages')]
    public function test_real_faults_leave_no_partial_graph_or_request_claim(string $stage): void
    {
        $s = $this->source(['price' => '0.00']);
        $command = $this->command($s);
        if ($stage !== 'before_commit') {
            $this->fault($stage);
        }
        $writer = $stage === 'before_commit' ? app()->make(InvoiceBeforeCommitFaultWriter::class) : app(InvoiceIssuanceService::class);
        $before = $this->fingerprints();
        $this->assertFailure('integrity_violation', fn () => $writer->issue($command), '23514', 'test_issue_fault');
        $this->assertEquals($before, $this->fingerprints());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::table('billing_requests')->where('request_id', $command->requestId)->exists());
        $this->assertFalse(DB::table('invoices')->where('elderly_id', $s['elderly']->id)->exists());
    }

    public function test_unknown_commit_acknowledgement_reconciles_only_committed_durable_outcome(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $result = app()->make(InvoiceLostCommitAckWriter::class)->issue($command);
        $this->assertTrue($result->replayed);
        $this->assertSame(1, DB::table('invoices')->where('elderly_id', $s['elderly']->id)->count());
        $this->assertSame(1, DB::table('billing_requests')->where('request_id', $command->requestId)->count());
        $other = $this->source();
        $notCommitted = $this->command($other);
        $this->assertFailure('unknown_commit_outcome', fn () => app()->make(InvoiceUnknownUncommittedWriter::class)->issue($notCommitted), '08007');
        $this->assertFalse(DB::table('invoices')->where('elderly_id', $other['elderly']->id)->exists());
    }
}

class InvoiceBeforeCommitFaultWriter extends InvoiceIssuanceService
{
    protected function commit(): void
    {
        DB::statement("DO $$ BEGIN RAISE EXCEPTION USING ERRCODE='23514',CONSTRAINT='test_issue_fault',MESSAGE='test_issue_fault'; END $$");
    }
}

class InvoiceLostCommitAckWriter extends InvoiceIssuanceService
{
    private bool $lost = false;

    protected function commit(): void
    {
        parent::commit();
        if (! $this->lost) {
            $this->lost = true;
            $error = new PDOException('Simulated lost COMMIT acknowledgement');
            $error->errorInfo = ['08007', 0, 'test-only'];
            throw $error;
        }
    }
}

class InvoiceUnknownUncommittedWriter extends InvoiceIssuanceService
{
    protected function commit(): void
    {
        $error = new PDOException('Simulated unknown COMMIT');
        $error->errorInfo = ['08007', 0, 'test-only'];
        throw $error;
    }
}
