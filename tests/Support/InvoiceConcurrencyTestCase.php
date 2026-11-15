<?php

namespace Tests\Support;

use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceCanonical;
use App\Services\Billing\InvoiceIssueCommand;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

abstract class InvoiceConcurrencyTestCase extends InvoiceIssuanceFeatureTestCase
{
    protected array $workers = [];

    protected string $caseId;

    protected string $evidenceEnvironment = 'SILANSIA_P92D_EVIDENCE_FILE';

    protected string $evidencePrefix = 'SILANSIA_P9_2D_TEST_EVIDENCE_';

    private array $faultObjects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->caseId = 'p92d_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $input]) {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(1); // Hanya proses yang dibuat test case ini.
            }
        }
        if ($this->faultObjects !== []) {
            PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
            foreach ($this->faultObjects as [$table, $name, $sequence]) {
                DB::statement("DROP TRIGGER {$name} ON public.{$table}");
                DB::statement("DROP FUNCTION public.{$name}()");
                if ($sequence) {
                    DB::statement("DROP SEQUENCE public.{$sequence}");
                }
            }
        }
        parent::tearDown();
    }

    protected function spawn(array $payload): int
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $input = new InputStream;
        $process = new Process([PHP_BINARY, 'tests/Support/invoice-concurrency-worker.php', json_encode(['case' => $this->caseId, ...$payload], JSON_THROW_ON_ERROR)],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true',
                'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-test-only-approval', 'INVOICE_ISSUANCE_ENABLED' => 'true'], $input);
        $process->setTimeout(70);
        $process->start();
        $index = count($this->workers);
        $this->workers[] = [$process, $input, $process->getPid(), $payload];
        $ready = $this->awaitPoint($index, 'ready');
        $identity = PostgresTestGuard::fromEnvironment()->identity;
        $this->assertSame($identity['database'], $ready['database']);
        $this->assertSame($identity['oid'], $ready['oid']);
        $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $ready['backend_pid']);
        // Windows proc_open dapat mengembalikan PID launcher, bukan PID interpreter PHP.
        $this->assertGreaterThan(0, $ready['os_pid']);
        $this->assertNotSame(getmypid(), $ready['os_pid']);

        return $index;
    }

    protected function release(int $worker): void
    {
        $before = count($this->events($worker));
        $this->workers[$worker][1]->write("go\n");
        // InputStream dipompa oleh Process yang bersangkutan; jangan menunggu peer
        // sebelum worker ini benar-benar mengakui release (penting di Windows).
        $deadline = microtime(true) + 3;
        do {
            if (count($this->events($worker)) > $before) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker belum mengakui pelepasan barrier.');
    }

    protected function events(int $worker): array
    {
        $this->workers[$worker][0]->isRunning();
        $rows = [];
        foreach (explode("\n", trim($this->workers[$worker][0]->getOutput())) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                if ($row['point'] === 'ready') {
                    $row['launcher_pid'] = $this->workers[$worker][2];
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function awaitPoint(int $worker, string $point, int $occurrence = 1): array
    {
        $deadline = microtime(true) + 12;
        do {
            foreach ($this->events($worker) as $event) {
                if ($event['point'] === $point && ($event['occurrence'] ?? 1) === $occurrence) {
                    return $event;
                }
            }
            if (! $this->workers[$worker][0]->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Barrier tidak tercapai: '.$point.'; '.json_encode($this->events($worker)).'; stderr='.$this->workers[$worker][0]->getErrorOutput());
    }

    protected function blockedBy(int $waiter, int $holder, int $waiterAttempt = 1): array
    {
        $waiting = $this->awaitPoint($waiter, 'begin', $waiterAttempt);
        $holding = $this->awaitPoint($holder, 'begin');
        $this->assertNotSame($waiting['backend_pid'], $holding['backend_pid']);
        $this->assertNotSame($waiting['os_pid'], $holding['os_pid']);
        $deadline = microtime(true) + 3.5;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $row = DB::selectOne('SELECT wait_event_type, xact_start::text AS started,pg_blocking_pids(pid)::text AS direct_blockers,
                ?::int=ANY(pg_blocking_pids(pid)) AS expected_blocker FROM pg_stat_activity WHERE pid=? AND datname=?',
                [$holding['backend_pid'], $waiting['backend_pid'], PostgresTestGuard::fromEnvironment()->identity['database']]);
            $reachable = $row && $row->wait_event_type === 'Lock' && ($row->expected_blocker || DB::scalar('WITH RECURSIVE chain(pid,depth) AS (
                SELECT unnest(pg_blocking_pids(?::int)),1 UNION ALL
                SELECT blocker,depth+1 FROM chain CROSS JOIN LATERAL unnest(pg_blocking_pids(chain.pid)) blocker WHERE depth<8
                ) SELECT EXISTS(SELECT 1 FROM chain WHERE pid=?::int)', [$waiting['backend_pid'], $holding['backend_pid']]));
            if ($reachable) {
                $this->assertNotNull($row->started);

                return ['waiter' => $waiting['backend_pid'], 'holder' => $holding['backend_pid'], 'direct_blockers' => $row->direct_blockers,
                    'holder_reachable' => true, 'lock_wait' => true];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Tidak ada overlap/expected PostgreSQL lock blocker; race tidak dianggap PASS.');
    }

    protected function finish(int $worker): array
    {
        $this->workers[$worker][1]->close();
        $this->workers[$worker][0]->wait();
        $this->assertSame(0, $this->workers[$worker][0]->getExitCode(), $this->workers[$worker][0]->getErrorOutput());
        $outcomes = array_values(array_filter($this->events($worker), fn ($e) => $e['point'] === 'outcome'));
        $this->assertCount(1, $outcomes);
        $this->assertNotSame('infrastructure', $outcomes[0]['kind'] ?? null, json_encode($outcomes[0]));

        return $outcomes[0];
    }

    protected function issuePayload(InvoiceIssueCommand $command, array $pause = [], string $fault = ''): array
    {
        return ['operation' => 'issue', 'command' => $command, 'pause' => $pause, 'fault' => $fault];
    }

    protected function databaseFault(string $stage, ?string $state = null, int $limit = 1): ?string
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $name = 'test_d_fault_'.bin2hex(random_bytes(5));
        $table = match ($stage) {
            'revision' => 'invoice_revisions', 'request' => 'billing_requests', 'action', 'zero' => 'billing_actions', default => 'invoices',
        };
        $sequence = $state ? $name.'_seq' : null;
        if ($sequence) {
            DB::statement("CREATE SEQUENCE public.{$sequence}");
        }
        $body = match (true) {
            $stage === 'statement' => 'PERFORM pg_sleep(0.3);',
            $state !== null => "IF nextval('public.{$sequence}') <= {$limit} THEN RAISE EXCEPTION USING ERRCODE='{$state}',MESSAGE='test-only injected SQLSTATE'; END IF;",
            default => "RAISE EXCEPTION USING ERRCODE='23514',CONSTRAINT='test_issue_fault',MESSAGE='test_issue_fault';",
        };
        $when = match ($stage) {
            'action' => "WHEN (NEW.action='invoice_issued')", 'zero' => "WHEN (NEW.action='zero_charge')",
            'revision', 'request' => '', default => 'WHEN (NEW.billing_schema_version=1)',
        };
        DB::unprepared("CREATE FUNCTION public.{$name}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN {$body} RETURN NEW; END \$f\$");
        DB::statement("CREATE TRIGGER {$name} AFTER INSERT ON public.{$table} FOR EACH ROW {$when} EXECUTE FUNCTION public.{$name}()");
        $this->faultObjects[] = [$table, $name, $sequence];

        return $sequence;
    }

    protected function disconnectOwnedWorker(int $worker, string $point): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $event = $this->awaitPoint($worker, $point);
        $this->assertSame(1, DB::table('pg_stat_activity')->where('pid', $event['backend_pid'])
            ->where('datname', PostgresTestGuard::fromEnvironment()->identity['database'])
            ->where('application_name', $this->caseId)->whereNotNull('xact_start')->count());
        $this->assertTrue(DB::scalar('SELECT pg_terminate_backend(?)', [$event['backend_pid']]));
    }

    protected function verifyInvoices(array $residentIds): array
    {
        $roots = DB::table('invoices')->whereIn('elderly_id', $residentIds)->get();
        $this->assertSame($roots->count(), $roots->unique(fn ($r) => $r->elderly_id.'|'.$r->billing_month)->count());
        foreach ($roots as $root) {
            $revisions = DB::table('invoice_revisions')->where('invoice_id', $root->id)->get();
            $this->assertCount(1, $revisions);
            $revision = $revisions->sole();
            $this->assertSame(1, $revision->revision_no);
            $this->assertSame($revision->id, $root->current_revision_id);
            $this->assertNull($revision->previous_revision_id);
            $this->assertSame(InvoiceCalculator::POLICY_VERSION, $revision->policy_version);
            $this->assertSame($root->amount, $revision->amount);
            $this->assertSame($root->initial_issued_by, $revision->issued_by);
            $this->assertSame($root->initial_issued_at, $revision->issued_at);
            $this->assertSame($root->amount === '0.00' ? 'paid' : 'unpaid', $root->status);
            if ($root->amount === '0.00') {
                $this->assertTrue(DB::scalar("SELECT ?::timestamptz=date_trunc('second',?::timestamptz)", [$root->paid_at, $root->initial_issued_at]));
            } else {
                $this->assertNull($root->paid_at);
            }
            $this->assertSame(DB::table('service_enrollments')->where('id', $root->service_enrollment_id)->value('monthly_price'), $revision->monthly_price);
            $this->assertSame($root->bill_to_family_id, DB::table('financial_ownership_periods')->where('id', $root->financial_ownership_period_id)->value('family_id'));
            $facts = [...(array) $revision, 'billing_month' => $root->billing_month, 'financial_owner_id' => $root->bill_to_family_id,
                'ownership_period_id' => $root->financial_ownership_period_id];
            $this->assertSame(app(InvoiceCanonical::class)->content($facts), $revision->content_hash);
            $this->assertTrue(DB::scalar("SELECT ?::date=(?::timestamptz AT TIME ZONE 'Asia/Jakarta')::date+7 AND ?::date=?::date+3", [$root->due_date, $revision->issued_at, $root->grace_period_ends_on, $root->due_date]));
            $requests = DB::table('billing_requests')->where('invoice_id', $root->id)->get();
            $this->assertCount(1, $requests);
            $this->assertSame($revision->id, $requests->sole()->outcome_revision_id);
            $this->assertSame('issue', $requests->sole()->operation);
            $this->assertNull($requests->sole()->outcome_payment_id);
            $this->assertSame($root->amount === '0.00' ? ['invoice_issued', 'zero_charge'] : ['invoice_issued'],
                DB::table('billing_actions')->where('invoice_id', $root->id)->orderBy('invoice_sequence')->pluck('action')->all());
            $this->assertSame($root->amount === '0.00' ? [1, 2] : [1],
                DB::table('billing_actions')->where('invoice_id', $root->id)->orderBy('invoice_sequence')->pluck('invoice_sequence')->all());
            $this->assertSame(0, DB::table('payments')->where('invoice_id', $root->id)->count());
            DB::select('SELECT public.billing_validate_invoice(?)', [$root->id]);
            DB::select('SELECT public.billing_validate_ownership(?)', [$root->elderly_id]);
        }

        return $roots->pluck('id')->all();
    }

    protected function preserve(array $before, array $allowed): void
    {
        $after = $this->fingerprints();
        foreach ($before as $table => $row) {
            if (! in_array($table, $allowed, true)) {
                $this->assertEquals($row, $after[$table], 'Tabel dilindungi: '.$table);
            }
        }
    }

    protected function evidence(string $scenario, array $outcomes, array $before, array $overlap): void
    {
        $trace = [];
        foreach (array_keys($this->workers) as $i) {
            $events = $this->events($i);
            $trace[] = $events;
            // Setiap attempt issuance hanya satu lock Users, dan tidak setelah lock elderly.
            $seenParent = [];
            foreach ($events as $event) {
                if ($event['point'] === 'lock_elderlies') {
                    $seenParent[$event['transaction']] = true;
                }
                if ($event['point'] === 'lock_users') {
                    $this->assertFalse($seenParent[$event['transaction']] ?? false, 'Late User lock tidak boleh terjadi.');
                }
            }
        }
        $after = $this->fingerprints();
        $billingTables = array_flip(['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions']);
        $residentIds = [];
        foreach ($this->workers as $worker) {
            $command = $worker[3]['command'] ?? null;
            $residentIds[] = $command instanceof InvoiceIssueCommand ? $command->elderlyId : ($worker[3]['resident'] ?? null);
        }
        foreach ($outcomes as $outcome) {
            $residentIds[] = $outcome['elderly_id'] ?? null;
        }
        $residentIds = array_values(array_unique(array_filter($residentIds)));
        $roots = DB::table('invoices')->whereIn('elderly_id', $residentIds)->orderBy('id')
            ->get(['id', 'elderly_id', 'billing_month', 'amount', 'status', 'current_revision_id', 'bill_to_family_id', 'financial_ownership_period_id', 'service_enrollment_id']);
        $rootIds = $roots->pluck('id')->all();
        $final = ['roots' => $roots->all()];
        foreach (['invoice_revisions', 'billing_requests', 'billing_actions', 'payments'] as $table) {
            $final[$table] = DB::table($table)->whereIn('invoice_id', $rootIds)->count();
        }
        $record = ['case' => $this->caseId, 'scenario' => $scenario,
            'database' => PostgresTestGuard::fromEnvironment()->identity['database'], 'overlap' => $overlap, 'outcomes' => $outcomes,
            'before' => hash('sha256', serialize($before)), 'after' => hash('sha256', serialize($after)),
            'source_before' => hash('sha256', serialize(array_diff_key($before, $billingTables))),
            'source_after' => hash('sha256', serialize(array_diff_key($after, $billingTables))), 'final_state' => $final,
            'financial_before' => hash('sha256', serialize(array_intersect_key($before, $billingTables))),
            'financial_after' => hash('sha256', serialize(array_intersect_key($after, $billingTables))),
            'events' => $trace];
        $path = getenv($this->evidenceEnvironment);
        if ($path !== false) {
            PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
            $expected = base_path('docs/report/'.$this->evidencePrefix.substr($record['database'], -16).'.jsonl');
            $this->assertSame($expected, $path);
            $this->assertNotFalse(file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX));
        }
        unset($record['events']);
        fwrite(STDOUT, 'P92D_CASE '.json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
