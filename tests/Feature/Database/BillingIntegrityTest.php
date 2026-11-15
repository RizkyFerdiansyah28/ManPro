<?php

namespace Tests\Feature\Database;

use App\Models\Elderly;
use App\Models\User;
use App\Services\ResidentService;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\PostgresFeatureTestCase;

#[Group('billing-integrity')]
class BillingIntegrityTest extends PostgresFeatureTestCase
{
    use BillingIntegrityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::commit(); // Semua assertions COMMIT harus memakai transaksi PostgreSQL nyata.
    }

    private function snapshot(array $g): string
    {
        $rows = [];
        foreach (['invoices', 'invoice_revisions', 'payments', 'billing_requests', 'billing_actions'] as $table) {
            $query = DB::table($table)->where($table === 'invoices' ? 'id' : 'invoice_id', $g['invoice']['id']);
            $rows[$table] = $query->orderBy('id')->get()->all();
        }
        $rows['periods'] = DB::table('financial_ownership_periods')->where('elderly_id', $g['p']['elderly'])->orderBy('id')->get()->all();
        $rows['elderly'] = DB::table('elderlies')->where('id', $g['p']['elderly'])->first();

        return hash('sha256', serialize($rows));
    }

    private function rejects(callable $operation, string $constraint, string $state = '23514'): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Transaksi invalid harus ditolak.');
        } catch (PDOException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            $this->assertStringContainsString($constraint, $exception->errorInfo[2]);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_inventory_has_immediate_guards_deferred_validators_and_no_activation_trigger(): void
    {
        $this->assertSame(12, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname LIKE 'billing_%'"));
        $this->assertSame(18, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'billing_%'"));
        $this->assertSame(6, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'billing_%_final' AND tgdeferrable AND tginitdeferred"));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('elderlies'::regclass,'users'::regclass,'service_enrollments'::regclass)"));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname LIKE 'billing_%' AND (prosecdef OR provolatile <> 'v')"));
        $this->assertNotNull(DB::scalar("SELECT to_regclass('public.billing_requests_invoice_index')"));
    }

    public static function immutableCases(): array
    {
        return [['revision-update', 'billing_revision_immutable'], ['revision-delete', 'billing_revision_immutable'],
            ['action-update', 'billing_action_immutable'], ['action-delete', 'billing_action_immutable'],
            ['request-update', 'billing_request_immutable'], ['request-delete', 'billing_request_immutable'],
            ['period-delete', 'billing_ownership_history_immutable'], ['period-identity', 'billing_ownership_close_only'],
            ['invoice-identity', 'billing_invoice_identity_immutable'], ['invoice-delete', 'billing_invoice_history_immutable'],
            ['invoice-downgrade', 'billing_legacy_version_immutable'], ['payment-downgrade', 'billing_legacy_version_immutable'],
            ['payment-submission', 'billing_payment_submission_immutable'], ['payment-delete', 'billing_payment_history_immutable'],
            ['verified-update', 'billing_payment_terminal_immutable'], ['rejected-update', 'billing_payment_terminal_immutable'],
            ['paid-revision', 'billing_revision_parent_state'], ['paid-update', 'billing_invoice_terminal_immutable'],
            ['cancelled-update', 'billing_invoice_terminal_immutable'], ['closed-period-update', 'billing_ownership_close_only']];
    }

    #[DataProvider('immutableCases')]
    public function test_immutable_and_terminal_facts_reject_exact_mutation(string $case, string $constraint): void
    {
        $g = $this->billingGraph();
        if (str_starts_with($case, 'payment') || in_array($case, ['verified-update', 'rejected-update', 'paid-update', 'paid-revision'], true)) {
            $g = DB::transaction(fn () => $this->billingSubmit($g));
        }
        if (in_array($case, ['verified-update', 'paid-update', 'paid-revision'], true)) {
            DB::transaction(fn () => $this->billingReview($g, 'verified'));
        } elseif ($case === 'rejected-update') {
            DB::transaction(fn () => $this->billingReview($g, 'rejected'));
        } elseif ($case === 'cancelled-update') {
            DB::transaction(fn () => $this->cancel($g));
        } elseif ($case === 'closed-period-update') {
            DB::transaction(fn () => $this->billingTransfer($g));
        }
        $before = $this->snapshot($g);
        $this->rejects(function () use ($g, $case) {
            match ($case) {
                'revision-update' => DB::table('invoice_revisions')->where('id', $g['revision']['id'])->update(['amount' => '10.00']),
                'revision-delete' => DB::table('invoice_revisions')->where('id', $g['revision']['id'])->delete(),
                'action-update' => DB::table('billing_actions')->where('invoice_id', $g['invoice']['id'])->update(['private_note' => 'changed']),
                'action-delete' => DB::table('billing_actions')->where('invoice_id', $g['invoice']['id'])->delete(),
                'request-update' => DB::table('billing_requests')->where('id', $g['request']['id'])->update(['intent_hash' => str_repeat('f', 64)]),
                'request-delete' => DB::table('billing_requests')->where('id', $g['request']['id'])->delete(),
                'period-delete' => DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->delete(),
                'period-identity' => DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['family_id' => $g['p']['next_family']]),
                'closed-period-update' => DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => '2026-12-01']),
                'invoice-identity' => DB::table('invoices')->where('id', $g['invoice']['id'])->update(['number' => 'CHANGED']),
                'invoice-delete' => DB::table('invoices')->where('id', $g['invoice']['id'])->delete(),
                'invoice-downgrade' => DB::table('invoices')->where('id', $g['invoice']['id'])->update(['billing_schema_version' => null]),
                'payment-downgrade' => DB::table('payments')->where('id', $g['payment']['id'])->update(['billing_schema_version' => null]),
                'payment-submission' => DB::table('payments')->where('id', $g['payment']['id'])->update(['proof_sha256' => str_repeat('c', 64)]),
                'payment-delete' => DB::table('payments')->where('id', $g['payment']['id'])->delete(),
                'verified-update', 'rejected-update' => DB::table('payments')->where('id', $g['payment']['id'])->update(['status' => 'pending', 'verified_by' => null, 'verified_at' => null]),
                'paid-revision' => $this->billingRevise($g),
                'paid-update', 'cancelled-update' => DB::table('invoices')->where('id', $g['invoice']['id'])->update(['status' => 'unpaid', 'paid_at' => null]),
            };
        }, $constraint);
        $this->assertSame($before, $this->snapshot($g));
    }

    private function cancel(array $g): void
    {
        $at = '2026-11-04 00:00:00.123456+07';
        DB::table('invoices')->where('id', $g['invoice']['id'])->update(['status' => 'cancelled', 'cancelled_by' => $g['p']['admin'],
            'cancelled_at' => $at, 'cancellation_reason' => 'Fixture cancellation']);
        $q = $this->billingRequest($g, 'cancel', $g['p']['admin'], $g['revision']['id']);
        $this->billingAction($g, $q, 'invoice_cancelled', $g['revision']['id'], null, $at);
    }

    public function test_valid_r1_r2_reject_resubmit_verify_and_zero_charge_commit(): void
    {
        $g = $this->billingGraph();
        $g = DB::transaction(fn () => $this->billingRevise($g));
        $this->assertSame(2, DB::table('invoice_revisions')->where('invoice_id', $g['invoice']['id'])->count());
        $g = DB::transaction(fn () => $this->billingSubmit($g));
        DB::transaction(fn () => $this->billingReview($g, 'rejected'));
        $g = DB::transaction(fn () => $this->billingSubmit($g));
        DB::transaction(fn () => $this->billingReview($g, 'verified'));
        $this->assertSame('paid', DB::table('invoices')->where('id', $g['invoice']['id'])->value('status'));
        $this->assertSame(1, DB::table('payments')->where('invoice_id', $g['invoice']['id'])->where('status', 'rejected')->count());
        foreach (['issue', 'revision'] as $kind) {
            $zero = $this->billingGraph($kind === 'issue' ? '0.00' : '3100.00');
            if ($kind === 'revision') {
                $old = DB::transaction(fn () => $this->billingSubmit($zero));
                DB::transaction(fn () => $this->billingReview($old, 'rejected'));
                $zero = DB::transaction(fn () => $this->billingRevise($zero, '0.00'));
            }
            $this->assertSame('paid', DB::table('invoices')->where('id', $zero['invoice']['id'])->value('status'));
            $this->assertSame(0, DB::table('payments')->where('invoice_id', $zero['invoice']['id'])->where('invoice_revision_id', $zero['revision']['id'])->count());
        }
    }

    public static function invalidGraphs(): array
    {
        return [['projection', 'billing_invoice_projection'], ['paid-without-payment', 'billing_invoice_payment_state'],
            ['revision-tip', 'billing_invoice_revision_graph'], ['revision-noop', 'billing_revision_material_change'],
            ['revision-fork', 'billing_revision_chain'], ['revision-pending', 'billing_revision_payment_conflict'],
            ['payment-amount', 'billing_payment_current_amount'], ['payment-old-revision', 'billing_payment_current_amount'],
            ['verification-no-paid', 'billing_invoice_payment_state'], ['audit-gap', 'billing_action_sequence'],
            ['audit-outcome', 'billing_action_outcome'], ['request-intent', 'billing_request_intent_conflict'],
            ['ownership-no-successor', 'billing_ownership_final_chain'], ['ownership-gap', 'billing_ownership_successor'],
            ['ownership-overlap', 'billing_ownership_successor'], ['ownership-family', 'billing_ownership_final_family'],
            ['ownership-backclose', 'billing_ownership_billed_coverage'], ['wrong-witness', 'billing_invoice_identity_immutable'],
            ['revision-no-audit', 'billing_invoice_revision_audit'], ['forged-audit-actor', 'billing_invoice_action_state']];
    }

    #[DataProvider('invalidGraphs')]
    public function test_each_invalid_graph_has_an_exact_contract_and_atomic_rollback(string $case, string $constraint): void
    {
        $g = $this->billingGraph();
        if (in_array($case, ['revision-pending', 'verification-no-paid'], true)) {
            $g = DB::transaction(fn () => $this->billingSubmit($g));
        }
        if ($case === 'payment-old-revision') {
            DB::transaction(fn () => $this->billingRevise($g));
        }
        $before = $this->snapshot($g);
        $this->rejects(function () use ($g, $case) {
            switch ($case) {
                case 'revision-no-audit':
                    $r = [...$g['revision'], 'id' => $this->billingId(), 'revision_no' => 2, 'previous_revision_id' => $g['revision']['id'],
                        'amount' => '2200.00', 'issued_at' => '2026-11-02 00:00:00.123456+07', 'due_date' => '2026-11-09',
                        'grace_period_ends_on' => '2026-11-12', 'reason_code' => 'material_correction', 'reason' => 'Fixture'];
                    DB::table('invoice_revisions')->insert($r);
                    DB::table('invoices')->where('id', $g['invoice']['id'])->update(['amount' => $r['amount'], 'current_revision_id' => $r['id'],
                        'due_date' => $r['due_date'], 'grace_period_ends_on' => $r['grace_period_ends_on']]);
                    break;
                case 'forged-audit-actor':
                    $q = $this->billingRequest($g, 'issue', $g['p']['next_family'], $g['revision']['id']);
                    $this->billingAction($g, $q, 'invoice_issued', $g['revision']['id'], null, $g['revision']['issued_at']);
                    break;
                case 'projection': DB::table('invoices')->where('id', $g['invoice']['id'])->update(['amount' => '1.00']);
                    break;
                case 'paid-without-payment': DB::table('invoices')->where('id', $g['invoice']['id'])->update(['status' => 'paid', 'paid_at' => '2026-11-04']);
                    break;
                case 'revision-tip': $this->billingRevise($g);
                    DB::table('invoices')->where('id', $g['invoice']['id'])->update(['current_revision_id' => $g['revision']['id']]);
                    break;
                case 'revision-noop': $this->billingRevise($g, '3100.00');
                    break;
                case 'revision-fork':
                    DB::table('invoice_revisions')->insert([...$g['revision'], 'id' => $this->billingId(), 'revision_no' => 3,
                        'previous_revision_id' => $g['revision']['id'], 'amount' => '1.00', 'reason_code' => 'material_correction', 'reason' => 'Fixture']);
                    break;
                case 'revision-pending': $this->billingRevise($g);
                    break;
                case 'payment-amount': $g['revision']['amount'] = '1.00';
                    $this->billingSubmit($g);
                    break;
                case 'payment-old-revision': $this->billingSubmit($g);
                    break;
                case 'verification-no-paid': DB::table('payments')->where('id', $g['payment']['id'])->update(['status' => 'verified', 'verified_by' => $g['p']['admin'], 'verified_at' => '2026-11-04']);
                    break;
                case 'audit-gap':
                    $a = (array) DB::table('billing_actions')->where('invoice_id', $g['invoice']['id'])->first();
                    DB::table('billing_actions')->insert([...$a, 'id' => $this->billingId(), 'invoice_sequence' => 3]);
                    break;
                case 'audit-outcome':
                    $a = (array) DB::table('billing_actions')->where('invoice_id', $g['invoice']['id'])->first();
                    DB::table('billing_actions')->insert([...$a, 'id' => $this->billingId(), 'invoice_sequence' => 2, 'revision_id' => $this->billingId()]);
                    break;
                case 'request-intent': DB::table('billing_requests')->insert([...$g['request'], 'id' => $this->billingId(), 'intent_hash' => str_repeat('f', 64)]);
                    break;
                case 'ownership-no-successor': DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => '2026-11-05']);
                    break;
                case 'ownership-gap': case 'ownership-overlap':
                    $at = '2026-11-05 00:00:00.123456+07';
                    DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => $at]);
                    $start = $case === 'ownership-gap' ? '2026-11-06' : '2026-11-04';
                    DB::table('financial_ownership_periods')->insert([...$g['period'], 'id' => $this->billingId(), 'family_id' => $g['p']['next_family'],
                        'source' => 'family_transfer', 'previous_period_id' => $g['period']['id'], 'effective_from' => $start, 'recorded_at' => $start]);
                    break;
                case 'ownership-family': $this->billingTransfer($g);
                    DB::table('elderlies')->where('id', $g['p']['elderly'])->update(['family_id' => $g['p']['family']]);
                    break;
                case 'ownership-backclose':
                    $at = '2026-10-15 00:00:00.123456+07';
                    DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => $at]);
                    $this->billingOwnershipAction($g['period'], 'ownership_closed', $g['p']['admin'], $at);
                    $p = [...$g['period'], 'id' => $this->billingId(), 'family_id' => $g['p']['next_family'], 'source' => 'family_transfer',
                        'previous_period_id' => $g['period']['id'], 'effective_from' => $at, 'recorded_at' => $at];
                    DB::table('financial_ownership_periods')->insert($p);
                    DB::table('elderlies')->where('id', $g['p']['elderly'])->update(['family_id' => $p['family_id']]);
                    $this->billingOwnershipAction($p, 'ownership_opened', $g['p']['admin'], $at);
                    break;
                case 'wrong-witness': DB::table('invoices')->where('id', $g['invoice']['id'])->update(['bill_to_family_id' => $g['p']['next_family']]);
                    break;
            }
        }, $constraint);
        $this->assertSame($before, $this->snapshot($g));
    }

    public function test_missing_audit_fails_at_real_commit_and_valid_retry_does_not_append(): void
    {
        $g = $this->billingGraph();
        $before = $this->snapshot($g);
        $reachedEnd = false;
        $this->rejects(function () use ($g, &$reachedEnd) {
            $this->billingRequest($g, 'cancel', $g['p']['admin'], $g['revision']['id']);
            $reachedEnd = true;
        }, 'billing_invoice_request_audit');
        $this->assertTrue($reachedEnd);
        $this->assertSame($before, $this->snapshot($g));
        DB::transaction(function () use ($g) {
            $q = [...$g['request'], 'id' => $this->billingId()];
            $this->assertSame(0, DB::table('billing_requests')->insertOrIgnore($q));
            $this->assertSame($g['request']['id'], DB::table('billing_requests')->where('actor_id', $q['actor_id'])->where('request_id', $q['request_id'])->value('id'));
        });
        $this->assertSame($before, $this->snapshot($g));
    }

    public function test_legacy_writers_remain_compatible_without_activating_capture(): void
    {
        $g = $this->billingGraph();
        $admin = User::findOrFail($g['p']['admin']);
        $service = app(ResidentService::class);
        $resident = $service->create($admin, ['family_id' => $g['p']['family'], 'name' => 'Fixture legacy resident', 'birth_date' => '1950-01-01']);
        $service->reassignFamily($admin, $resident, ['family_id' => $g['p']['next_family'], 'current_family_id' => $g['p']['family'], 'confirm_reassignment' => true]);
        $this->assertSame(0, DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->count());
        // Bahkan resident yang sudah captured belum diberi mandatory-transfer gate P9-1C.
        $service->reassignFamily($admin, Elderly::findOrFail($g['p']['elderly']), ['family_id' => $g['p']['next_family'], 'current_family_id' => $g['p']['family'], 'confirm_reassignment' => true]);
        $this->assertSame(1, DB::table('financial_ownership_periods')->where('elderly_id', $g['p']['elderly'])->count());
        $this->assertSame($g['p']['family'], DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->value('family_id'));
    }

    public function test_ownership_transfer_has_a_single_open_leaf_and_preserves_invoice_owner(): void
    {
        $g = $this->billingGraph();
        $next = DB::transaction(fn () => $this->billingTransfer($g));
        $this->assertSame($next['id'], DB::table('financial_ownership_periods')->where('elderly_id', $g['p']['elderly'])->whereNull('effective_to')->value('id'));
        $this->assertSame($g['p']['family'], DB::table('invoices')->where('id', $g['invoice']['id'])->value('bill_to_family_id'));
        DB::statement('SELECT public.billing_validate_ownership(?)', [$g['p']['elderly']]);
        DB::statement('SELECT public.billing_validate_invoice(?)', [$g['invoice']['id']]);
    }

    public function test_managed_repeatable_read_is_rejected_without_session_bypass(): void
    {
        $g = $this->billingGraph();
        $this->rejects(function () use ($g) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->billingRevise($g);
        }, 'billing_isolation_required');
    }

    public function test_legacy_invoice_payment_are_mutable_but_cannot_be_promoted_to_managed(): void
    {
        $g = $this->billingGraph();
        $legacy = array_intersect_key($g['invoice'], array_flip(['id', 'number', 'elderly_id', 'service_enrollment_id', 'billing_month', 'amount',
            'currency', 'due_date', 'grace_period_ends_on', 'status', 'paid_at']));
        $legacy = [...$legacy, 'id' => $this->billingId(), 'number' => 'LEGACY-'.$this->billingId(), 'billing_month' => '2026-09-01'];
        DB::table('invoices')->insert($legacy);
        $payment = ['id' => $this->billingId(), 'invoice_id' => $legacy['id'], 'submitted_by' => $g['p']['family'], 'amount' => '3100.00',
            'proof_path' => 'synthetic/legacy.png', 'submitted_at' => '2026-11-01', 'status' => 'pending'];
        DB::table('payments')->insert($payment);
        DB::table('invoices')->where('id', $legacy['id'])->update(['amount' => '1.00']);
        DB::table('payments')->where('id', $payment['id'])->update(['status' => 'verified', 'verified_by' => $g['p']['admin'], 'verified_at' => '2026-11-02']);
        $this->assertSame('1.00', DB::table('invoices')->where('id', $legacy['id'])->value('amount'));
        foreach (['invoices' => $legacy['id'], 'payments' => $payment['id']] as $table => $id) {
            $before = DB::table($table)->where('id', $id)->first();
            $this->assertNull($before->billing_schema_version);
            $this->rejects(fn () => DB::table($table)->where('id', $id)->update(['billing_schema_version' => 1]), 'billing_legacy_version_immutable');
            $this->assertEquals($before, DB::table($table)->where('id', $id)->first());
        }
    }

    public function test_truncate_guard_prevents_history_loss_and_zero_charge_rejects_payment(): void
    {
        $g = $this->billingGraph('0.00');
        $before = $this->snapshot($g);
        $this->rejects(fn () => DB::statement('TRUNCATE TABLE billing_actions'), 'billing_history_truncate_forbidden');
        $this->rejects(fn () => $this->billingSubmit($g), 'billing_payment_current_amount');
        $this->assertSame($before, $this->snapshot($g));
    }

    public function test_guard_rollback_refuses_committed_history_and_preserves_catalog(): void
    {
        $g = $this->billingGraph();
        $before = $this->snapshot($g);
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        try {
            (require database_path('migrations/2026_11_13_000009_add_billing_integrity_guards.php'))->down();
            $this->fail('Rollback guard harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($g));
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertSame(18, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'billing_%'"));
    }
}
