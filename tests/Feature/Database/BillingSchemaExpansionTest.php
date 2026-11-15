<?php

namespace Tests\Feature\Database;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresFeatureTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('billing-expansion-schema')]
class BillingSchemaExpansionTest extends PostgresFeatureTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_12_000008_expand_billing_schema.php');
    }

    private function id(): string
    {
        return (string) Str::uuid();
    }

    private function parents(): array
    {
        $this->seed(ServiceClassSeeder::class);
        $family = $this->account('keluarga');
        $admin = $this->account();
        $elderly = Elderly::create(['family_id' => $family->id, 'name' => 'Fixture billing', 'birth_date' => '1950-01-01', 'is_active' => true]);
        $enrollment = ServiceEnrollment::create(['elderly_id' => $elderly->id,
            'service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
            'monthly_price' => '3100.00', 'started_at' => '2026-10-01 00:00:00+07']);

        return ['family' => $family->id, 'admin' => $admin->id, 'elderly' => $elderly->id, 'enrollment' => $enrollment->id];
    }

    private function invoice(array $p, array $changes = []): array
    {
        return array_replace(['id' => $this->id(), 'number' => 'FIXTURE-'.$this->id(), 'elderly_id' => $p['elderly'],
            'service_enrollment_id' => $p['enrollment'], 'billing_month' => '2026-10-01', 'amount' => '3100.00',
            'currency' => 'IDR', 'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11', 'status' => 'unpaid',
            'paid_at' => null, 'created_at' => '2026-11-01 00:00:00+07', 'updated_at' => '2026-11-01 00:00:00+07'], $changes);
    }

    private function period(array $p, array $changes = []): array
    {
        return array_replace(['id' => $this->id(), 'elderly_id' => $p['elderly'], 'family_id' => $p['family'],
            'previous_period_id' => null, 'effective_from' => '2026-10-01 00:00:00.123456+07', 'effective_to' => null,
            'recorded_by' => $p['admin'], 'recorded_at' => '2026-10-01 00:00:00.123456+07', 'source' => 'cutover_capture'], $changes);
    }

    private function revision(array $p, array $invoice, array $changes = []): array
    {
        return array_replace(['id' => $this->id(), 'invoice_id' => $invoice['id'], 'elderly_id' => $p['elderly'],
            'service_enrollment_id' => $p['enrollment'], 'revision_no' => 1, 'previous_revision_id' => null,
            'issued_by' => $p['admin'], 'issued_at' => '2026-11-01 23:59:59.123456+07', 'policy_version' => 'fixture-v1',
            'monthly_price' => '3100.00', 'effective_started_at' => '2026-10-01 00:00:00.123456+07',
            'effective_ended_at' => '2026-11-01 00:00:00.123456+07', 'chargeable_days' => 31, 'days_in_month' => 31,
            'amount' => '3100.00', 'currency' => 'IDR', 'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11',
            'reason_code' => 'initial_issue', 'reason' => null, 'content_hash' => str_repeat('a', 64)], $changes);
    }

    private function payment(array $p, array $invoice, array $changes = []): array
    {
        return array_replace(['id' => $this->id(), 'invoice_id' => $invoice['id'], 'submitted_by' => $p['family'],
            'amount' => '3100.00', 'proof_path' => 'fixture/private.png', 'submitted_at' => '2026-11-02 00:00:00+07',
            'status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'verification_notes' => null], $changes);
    }

    private function graph(): array
    {
        $p = $this->parents();
        $period = $this->period($p);
        DB::table('financial_ownership_periods')->insert($period);
        $invoice = $this->invoice($p);
        $revision = $this->revision($p, $invoice);
        DB::transaction(function () use ($p, $period, &$invoice, $revision) {
            $invoice += ['billing_schema_version' => 1, 'bill_to_family_id' => $p['family'],
                'financial_ownership_period_id' => $period['id'], 'initial_issued_by' => $p['admin'],
                'initial_issued_at' => $revision['issued_at'], 'current_revision_id' => $revision['id']];
            DB::table('invoices')->insert($invoice);
            DB::table('invoice_revisions')->insert($revision);
        });
        $payment = $this->payment($p, $invoice, ['billing_schema_version' => 1, 'invoice_revision_id' => $revision['id'],
            'submission_channel' => 'family_upload', 'proof_sha256' => str_repeat('b', 64), 'proof_size_bytes' => 2097152,
            'proof_mime' => 'image/png', 'proof_disk' => 'local']);
        DB::table('payments')->insert($payment);
        $request = ['id' => $this->id(), 'actor_id' => $p['admin'], 'request_id' => $this->id(), 'operation' => 'issue',
            'intent_hash' => str_repeat('c', 64), 'invoice_id' => $invoice['id'], 'outcome_payment_id' => null,
            'outcome_revision_id' => $revision['id'], 'completed_at' => '2026-11-01 23:59:59.123456+07'];
        DB::table('billing_requests')->insert($request);
        $action = ['id' => $this->id(), 'billing_request_id' => $request['id'], 'actor_id' => $p['admin'],
            'invoice_id' => $invoice['id'], 'ownership_period_id' => null, 'payment_id' => null, 'revision_id' => $revision['id'],
            'invoice_sequence' => 1, 'action' => 'invoice_issued', 'occurred_at' => $request['completed_at'],
            'reason_code' => null, 'private_note' => null, 'details' => '{"v":1}'];
        DB::table('billing_actions')->insert($action);

        return compact('p', 'period', 'invoice', 'revision', 'payment', 'request', 'action');
    }

    private function rejects(callable $operation, string $state, string $constraint): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Constraint harus menolak fixture invalid.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            $this->assertStringContainsString('"'.$constraint.'"', $exception->errorInfo[2]);
        }
    }

    private function catalog(): array
    {
        return [DB::select("SELECT table_name,column_name,data_type,is_nullable,column_default,numeric_precision,numeric_scale,datetime_precision,character_maximum_length
            FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position"),
            DB::select("SELECT c.relname,co.conname,pg_get_constraintdef(co.oid) AS definition,co.condeferrable,co.condeferred,co.convalidated
            FROM pg_constraint co JOIN pg_class c ON c.oid=co.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' ORDER BY c.relname,co.conname"),
            DB::select("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname")];
    }

    public function test_schema_manifest_precisions_defaults_and_deferred_target_keys(): void
    {
        $g = $this->graph();
        $counts = ['financial_ownership_periods' => 9, 'invoice_revisions' => 21, 'billing_requests' => 9, 'billing_actions' => 13];
        foreach ($counts as $table => $count) {
            $columns = DB::select('SELECT * FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]);
            $this->assertCount($count, $columns);
            foreach ($columns as $c) {
                $this->assertNull($c->column_default, $table.'.'.$c->column_name);
                if ($c->data_type === 'timestamp with time zone') {
                    $this->assertSame(6, $c->datetime_precision);
                }
                if ($c->data_type === 'numeric') {
                    $this->assertSame(14, $c->numeric_precision);
                    $this->assertSame(2, $c->numeric_scale);
                }
            }
        }
        $constraint = DB::selectOne("SELECT condeferrable,condeferred,confdeltype,confupdtype FROM pg_constraint WHERE conname='invoices_current_revision_foreign'");
        $this->assertTrue($constraint->condeferrable);
        $this->assertTrue($constraint->condeferred);
        $this->assertSame('a', $constraint->confdeltype);
        $this->assertSame('a', $constraint->confupdtype);
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid IN ('invoice_revisions'::regclass,'financial_ownership_periods'::regclass,'billing_requests'::regclass,'payments'::regclass,'invoices'::regclass) AND contype='u' AND condeferrable"));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('invoices'::regclass,'payments'::regclass,'financial_ownership_periods'::regclass,'invoice_revisions'::regclass,'billing_requests'::regclass,'billing_actions'::regclass)"));
        $this->assertSame('123456', DB::scalar("SELECT to_char(issued_at,'US') FROM invoice_revisions WHERE id=?", [$g['revision']['id']]));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated"));
    }

    public function test_exact_lengths_and_new_column_precision_match_the_approved_manifest(): void
    {
        $lengths = ['financial_ownership_periods' => ['source' => 24],
            'invoice_revisions' => ['policy_version' => 40, 'currency' => 3, 'reason_code' => 40, 'content_hash' => 64],
            'payments' => ['submission_channel' => 24, 'proof_sha256' => 64, 'proof_mime' => 32, 'proof_disk' => 32],
            'billing_requests' => ['operation' => 32, 'intent_hash' => 64], 'billing_actions' => ['action' => 40, 'reason_code' => 40]];
        foreach ($lengths as $table => $columns) {
            foreach ($columns as $column => $length) {
                $this->assertSame($length, DB::scalar('SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?', [$table, $column]));
            }
        }
        foreach (['initial_issued_at', 'cancelled_at'] as $column) {
            $this->assertSame(6, DB::scalar("SELECT datetime_precision FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='invoices' AND column_name=?", [$column]));
        }
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND contype='f' AND confdeltype='c'"));
    }

    public function test_valid_new_monetary_boundaries_and_private_proof_metadata_are_accepted(): void
    {
        $g = $this->graph();
        foreach (['0.00', '123.45', '999999999999.99'] as $amount) {
            DB::table('invoice_revisions')->where('id', $g['revision']['id'])->update(['amount' => $amount, 'monthly_price' => $amount]);
            $this->assertSame($amount, DB::table('invoice_revisions')->where('id', $g['revision']['id'])->value('amount'));
            $this->assertSame($amount, DB::table('invoice_revisions')->where('id', $g['revision']['id'])->value('monthly_price'));
        }
        foreach ([1, 2097152] as $bytes) {
            DB::table('payments')->where('id', $g['payment']['id'])->update(['proof_size_bytes' => $bytes, 'proof_mime' => 'image/jpeg']);
            $this->assertSame($bytes, DB::table('payments')->where('id', $g['payment']['id'])->value('proof_size_bytes'));
        }
    }

    public static function invalidFields(): array
    {
        return [
            ['revision', 'monthly_price', 'NaN', 'billing_revisions_price_check'],
            ['revision', 'amount', 'NaN', 'billing_revisions_amount_check'],
            ['revision', 'monthly_price', '-0.01', 'billing_revisions_price_check'],
            ['revision', 'amount', '-0.01', 'billing_revisions_amount_check'],
            ['revision', 'currency', 'USD', 'billing_revisions_currency_check'],
            ['revision', 'chargeable_days', 32, 'billing_revisions_days_check'],
            ['revision', 'content_hash', 'BAD', 'billing_revisions_hash_check'],
            ['revision', 'due_date', '2026-11-09', 'billing_revisions_due_check'],
            ['revision', 'grace_period_ends_on', '2026-11-12', 'billing_revisions_grace_check'],
            ['revision', 'policy_version', ' ', 'billing_revisions_policy_check'],
            ['revision', 'effective_ended_at', '2026-09-01', 'billing_revisions_interval_check'],
            ['period', 'source', 'invalid', 'billing_ownership_source_check'],
            ['period', 'effective_to', '2026-09-01', 'billing_ownership_period_check'],
            ['payment', 'submission_channel', 'public', 'payments_billing_channel_check'],
            ['payment', 'proof_sha256', 'BAD', 'payments_billing_hash_check'],
            ['payment', 'proof_size_bytes', 0, 'payments_billing_size_check'],
            ['payment', 'proof_size_bytes', 2097153, 'payments_billing_size_check'],
            ['payment', 'proof_mime', 'text/plain', 'payments_billing_mime_check'],
            ['payment', 'proof_disk', 'public', 'payments_billing_disk_check'],
            ['payment', 'public_rejection_reason', ' ', 'payments_billing_rejection_check'],
            ['request', 'operation', 'invalid', 'billing_requests_operation_check'],
            ['request', 'intent_hash', 'BAD', 'billing_requests_hash_check'],
            ['request', 'outcome_revision_id', null, 'billing_requests_outcome_check'],
            ['action', 'action', 'invalid', 'billing_actions_action_check'],
            ['action', 'invoice_sequence', 0, 'billing_actions_aggregate_check'],
            ['action', 'details', '{}', 'billing_actions_details_check'],
            ['action', 'details', '{"v":"1"}', 'billing_actions_details_check'],
            ['action', 'details', '[]', 'billing_actions_details_check'],
            ['action', 'reason_code', 'INVALID!', 'billing_actions_reason_check'],
            ['invoice', 'billing_schema_version', 2, 'invoices_billing_version_check'],
            ['invoice', 'initial_issued_by', null, 'invoices_billing_fields_check'],
            ['payment', 'billing_schema_version', 2, 'payments_billing_version_check'],
            ['payment', 'proof_mime', null, 'payments_billing_fields_check'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_local_checks_reject_only_the_intended_invalid_field(string $entity, string $field, mixed $value, string $constraint): void
    {
        $g = $this->graph(); // Semua row sudah berhasil INSERT sebelum satu field diubah.
        $tables = ['revision' => 'invoice_revisions', 'period' => 'financial_ownership_periods', 'payment' => 'payments',
            'request' => 'billing_requests', 'action' => 'billing_actions', 'invoice' => 'invoices'];
        $table = $tables[$entity];
        $before = DB::table($table)->where('id', $g[$entity]['id'])->first();
        $this->rejects(fn () => DB::table($table)->where('id', $g[$entity]['id'])->update([$field => $value]), '23514', $constraint);
        $this->assertEquals($before, DB::table($table)->where('id', $g[$entity]['id'])->first());
    }

    public function test_composite_contexts_predecessors_and_history_deletions_are_restricted(): void
    {
        $a = $this->graph();
        $b = $this->graph();
        foreach ([['payments', $a['payment'], 'invoice_revision_id', $b['revision']['id'], 'payments_billing_revision_foreign'],
            ['invoice_revisions', $a['revision'], 'elderly_id', $b['p']['elderly'], 'billing_revisions_invoice_context_foreign'],
            ['invoices', $a['invoice'], 'bill_to_family_id', $b['p']['family'], 'invoices_billing_owner_foreign'],
            ['billing_requests', $a['request'], 'outcome_revision_id', $b['revision']['id'], 'billing_requests_revision_foreign'],
            ['billing_actions', $a['action'], 'revision_id', $b['revision']['id'], 'billing_actions_revision_foreign'],
            ['billing_actions', $a['action'], 'billing_request_id', $b['request']['id'], 'billing_actions_request_foreign'],
        ] as [$table, $row, $field, $value, $constraint]) {
            $this->rejects(fn () => DB::table($table)->where('id', $row['id'])->update([$field => $value]), '23503', $constraint);
        }
        $r2 = $this->revision($a['p'], $a['invoice'], ['revision_no' => 2, 'previous_revision_id' => $b['revision']['id'],
            'reason_code' => 'material_correction', 'reason' => 'Fixture koreksi material']);
        $this->rejects(fn () => DB::table('invoice_revisions')->insert($r2), '23503', 'billing_revisions_previous_foreign');
        $p2 = $this->period($a['p'], ['source' => 'family_transfer', 'previous_period_id' => $b['period']['id'],
            'effective_to' => '2026-11-01']);
        $this->rejects(fn () => DB::table('financial_ownership_periods')->insert($p2), '23503', 'billing_ownership_previous_foreign');
        $this->rejects(fn () => DB::table('invoice_revisions')->where('id', $a['revision']['id'])->delete(), '23001', 'payments_billing_revision_foreign');
        $this->rejects(fn () => DB::table('financial_ownership_periods')->where('id', $a['period']['id'])->delete(), '23001', 'invoices_billing_owner_foreign');
        $this->rejects(fn () => DB::table('billing_requests')->insert([...$a['request'], 'id' => $this->id(),
            'request_id' => $this->id(), 'actor_id' => $this->id()]), '23503', 'billing_requests_actor_foreign');
        DB::table('invoice_revisions')->insert([...$r2, 'previous_revision_id' => $a['revision']['id']]);
    }

    public function test_partial_uniques_and_idempotency_keys_are_independent(): void
    {
        $g = $this->graph();
        $p = $g['p'];
        $this->rejects(fn () => DB::table('financial_ownership_periods')->insert($this->period($p, ['effective_to' => '2026-11-01'])), '23505', 'billing_ownership_root_unique');
        $this->rejects(fn () => DB::table('financial_ownership_periods')->insert($this->period($p,
            ['source' => 'family_transfer', 'previous_period_id' => $g['period']['id']])), '23505', 'billing_ownership_open_unique');
        DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => '2026-11-01']);
        $successor = $this->period($p, ['source' => 'family_transfer', 'previous_period_id' => $g['period']['id'], 'effective_to' => '2026-11-02']);
        DB::table('financial_ownership_periods')->insert($successor);
        $this->rejects(fn () => DB::table('financial_ownership_periods')->insert([...$successor, 'id' => $this->id()]), '23505', 'billing_ownership_successor_unique');
        $this->rejects(fn () => DB::table('payments')->insert($this->payment($p, $g['invoice'])), '23505', 'payments_one_pending_per_invoice');
        $verified = $this->payment($p, $g['invoice'], ['status' => 'verified', 'verified_by' => $p['admin'], 'verified_at' => '2026-11-02 01:00:00+07']);
        DB::table('payments')->insert($verified);
        $this->rejects(fn () => DB::table('payments')->insert([...$verified, 'id' => $this->id()]), '23505', 'payments_one_verified_per_invoice');
        $rejected = [...$verified, 'status' => 'rejected', 'id' => $this->id()];
        DB::table('payments')->insert($rejected);
        DB::table('payments')->insert([...$rejected, 'id' => $this->id()]);
        $this->rejects(fn () => DB::table('billing_requests')->insert([...$g['request'], 'id' => $this->id()]), '23505', 'billing_requests_actor_request_unique');
        $this->rejects(fn () => DB::table('billing_actions')->insert([...$g['action'], 'id' => $this->id()]), '23505', 'billing_actions_sequence_unique');
        $this->rejects(fn () => DB::table('invoice_revisions')->insert([...$g['revision'], 'id' => $this->id()]), '23505', 'billing_revisions_sequence_unique');
    }

    public function test_legacy_migration_down_up_preserves_all_old_schema_and_values_without_backfill(): void
    {
        $this->migration()->down();
        $p = $this->parents();
        $invoice = $this->invoice($p);
        DB::table('invoices')->insert($invoice);
        DB::table('payments')->insert($this->payment($p, $invoice));
        $legacy = $this->catalog();
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        $rows = [DB::table('invoices')->get()->all(), DB::table('payments')->get()->all()];
        $this->migration()->up();
        foreach (['invoices', 'payments'] as $index => $table) {
            $this->assertEquals($rows[$index], DB::table($table)->select(array_keys((array) $rows[$index][0]))->get()->all());
            $oldColumns = array_keys((array) $rows[$index][0]);
            foreach (DB::select('SELECT * FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $column) {
                if (! in_array($column->column_name, $oldColumns, true)) {
                    $this->assertSame('YES', $column->is_nullable);
                    $this->assertNull($column->column_default);
                    $this->assertNull(DB::table($table)->value($column->column_name));
                }
            }
        }
        $expanded = $this->catalog();
        $this->migration()->down();
        $this->assertEquals($legacy, $this->catalog());
        $this->assertEquals($rows, [DB::table('invoices')->get()->all(), DB::table('payments')->get()->all()]);
        $this->migration()->up();
        $this->assertEquals($expanded, $this->catalog());
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
    }

    public static function duplicateStatuses(): array
    {
        return [['pending'], ['verified']];
    }

    #[DataProvider('duplicateStatuses')]
    public function test_preflight_duplicate_legacy_payments_stops_before_any_schema_change(string $status): void
    {
        $this->migration()->down();
        $p = $this->parents();
        $invoice = $this->invoice($p);
        DB::table('invoices')->insert($invoice);
        $payment = $this->payment($p, $invoice, $status === 'verified'
            ? ['status' => $status, 'verified_by' => $p['admin'], 'verified_at' => '2026-11-02 01:00:00+07'] : []);
        DB::table('payments')->insert($payment);
        DB::table('payments')->insert([...$payment, 'id' => $this->id()]);
        $before = [$this->catalog(), DB::table('payments')->orderBy('id')->get()->all(), DB::table('migrations')->orderBy('id')->get()->all()];
        try {
            $this->migration()->up();
            $this->fail('Preflight harus berhenti.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Perluasan billing ditolak: terdapat payment pending/verified duplikat per invoice. Data tidak diubah.', $exception->getMessage());
        }
        $this->assertEquals($before, [$this->catalog(), DB::table('payments')->orderBy('id')->get()->all(), DB::table('migrations')->orderBy('id')->get()->all()]);
    }

    public function test_down_refuses_new_rows_and_even_partial_metadata_without_deleting_history(): void
    {
        $p = $this->parents();
        $invoice = $this->invoice($p, ['initial_issued_at' => '2026-11-01 00:00:00+07']);
        DB::table('invoices')->insert($invoice);
        foreach (['metadata', 'new-row'] as $case) {
            if ($case === 'new-row') {
                DB::table('invoices')->where('id', $invoice['id'])->update(['initial_issued_at' => null]);
                DB::table('financial_ownership_periods')->insert($this->period($p));
            }
            $before = [$this->catalog(), DB::table('invoices')->get()->all(), DB::table('financial_ownership_periods')->get()->all()];
            try {
                $this->migration()->down();
                $this->fail('Rollback berisiko harus ditolak.');
            } catch (RuntimeException $exception) {
                $this->assertSame(RuntimeException::class, $exception::class);
                $this->assertSame('Rollback perluasan billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.', $exception->getMessage());
            }
            $this->assertEquals($before, [$this->catalog(), DB::table('invoices')->get()->all(), DB::table('financial_ownership_periods')->get()->all()]);
        }
    }

    public function test_circular_fk_at_real_commit_and_atomic_failures_leave_no_orphans(): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $classIds = DB::table('service_classes')->pluck('id')->all();
        $p = $this->parents();
        $period = $this->period($p);
        DB::table('financial_ownership_periods')->insert($period);
        $createdClassIds = DB::table('service_classes')->whereNotIn('id', $classIds)->pluck('id')->all();
        DB::commit(); // Fixture parent selesai; transaksi berikutnya benar-benar mencapai COMMIT PostgreSQL.
        $invoiceIds = [];
        try {
            $invoice = $this->invoice($p);
            $revision = $this->revision($p, $invoice);
            $invoice += ['current_revision_id' => $revision['id'], 'billing_schema_version' => 1,
                'bill_to_family_id' => $p['family'], 'financial_ownership_period_id' => $period['id'],
                'initial_issued_by' => $p['admin'], 'initial_issued_at' => $revision['issued_at']];
            $invoiceIds[] = $invoice['id'];
            DB::transaction(function () use ($invoice, $revision) {
                $this->assertSame(1, DB::transactionLevel());
                DB::table('invoices')->insert($invoice);
                DB::table('invoice_revisions')->insert($revision);
            });
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue(DB::table('invoice_revisions')->where('id', $revision['id'])->exists());
            foreach (['missing', 'foreign'] as $case) {
                $bad = $this->invoice($p, ['billing_month' => $case === 'missing' ? '2026-09-01' : '2026-08-01',
                    'current_revision_id' => $case === 'missing' ? $this->id() : $revision['id']]);
                $orphan = $this->revision($p, $bad);
                $invoiceIds[] = $bad['id'];
                $reachedEnd = false;
                try {
                    DB::transaction(function () use ($bad, $orphan, &$reachedEnd) {
                        DB::table('invoices')->insert($bad);
                        DB::table('invoice_revisions')->insert($orphan);
                        $reachedEnd = true;
                    });
                    $this->fail('FK harus gagal tepat saat COMMIT.');
                } catch (PDOException $exception) {
                    $this->assertTrue($reachedEnd);
                    $this->assertSame('23503', $exception->errorInfo[0]);
                    $this->assertStringContainsString('"invoices_current_revision_foreign"', $exception->errorInfo[2]);
                }
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::table('invoices')->where('id', $bad['id'])->exists());
                $this->assertFalse(DB::table('invoice_revisions')->where('id', $orphan['id'])->exists());
            }
            $legacy = $this->invoice($p, ['billing_month' => '2026-07-01']);
            $invoiceIds[] = $legacy['id'];
            DB::transaction(fn () => DB::table('invoices')->insert($legacy));
            $this->assertNull(DB::table('invoices')->where('id', $legacy['id'])->value('current_revision_id'));
        } finally {
            PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
            DB::transaction(function () use ($invoiceIds, $p, $period, $createdClassIds) {
                DB::table('invoice_revisions')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
                DB::table('financial_ownership_periods')->where('id', $period['id'])->delete();
                DB::table('service_enrollments')->where('id', $p['enrollment'])->delete();
                DB::table('elderlies')->where('id', $p['elderly'])->delete();
                DB::table('users')->whereIn('id', [$p['family'], $p['admin']])->delete();
                DB::table('service_classes')->whereIn('id', $createdClassIds)->delete();
            });
            DB::beginTransaction();
        }
    }
}
