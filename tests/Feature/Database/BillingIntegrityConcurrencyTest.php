<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\PostgresFeatureTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('billing-integrity')]
class BillingIntegrityConcurrencyTest extends PostgresFeatureTestCase
{
    use BillingIntegrityFixtures;

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::commit();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        parent::tearDown();
    }

    public static function races(): array
    {
        return [['revision-revision'], ['revision-submit'], ['revision-verify'], ['verify-reject'], ['close-successor'],
            ['successor-successor'], ['request-key'], ['audit-sequence'], ['paid-revision'], ['zero-transition']];
    }

    #[DataProvider('races')]
    public function test_independent_transactions_overlap_and_only_one_valid_outcome_commits(string $case): void
    {
        $g = $this->billingGraph();
        if (in_array($case, ['revision-verify', 'verify-reject', 'paid-revision'], true)) {
            $g = DB::transaction(fn () => $this->billingSubmit($g));
        }
        if ($case === 'paid-revision') {
            $g = DB::transaction(fn () => $this->billingReview($g, 'verified'));
        }
        $other = $g;
        if ($case === 'request-key') {
            $other = $this->billingGraph();
            $other['p']['admin'] = $g['p']['admin'];
        }
        [$one, $two] = match ($case) {
            'revision-revision', 'request-key' => ['revise', 'revise'],
            'revision-submit' => ['revise', 'submit'],
            'revision-verify' => ['revise', 'verify'],
            'verify-reject' => ['verify', 'reject'],
            'close-successor' => ['transfer', 'append_only'],
            'successor-successor' => ['transfer', 'transfer'],
            'audit-sequence' => ['submit', 'cancel'],
            'paid-revision' => ['retry', 'revise'],
            'zero-transition' => ['zero', 'submit'],
        };
        $key = $this->billingId();
        $payloads = [['graph' => $g, 'operation' => $one, 'amount' => '2200.00', 'request_id' => $key],
            ['graph' => $other, 'operation' => $two, 'amount' => '2100.00', 'request_id' => $key]];
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        DB::beginTransaction();
        try {
            // Hanya barrier tes; worker tetap mengambil seluruh ancestor dalam urutan produksi.
            DB::table('users')->where('id', $g['p']['admin'])->lockForUpdate()->first();
            foreach ($payloads as $payload) {
                $process = new Process([PHP_BINARY, 'tests/Support/billing-integrity-worker.php', json_encode($payload, JSON_THROW_ON_ERROR)],
                    base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
                $process->setTimeout(40);
                $process->start();
                $this->processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            $pids = [];
            do {
                foreach ($this->processes as $index => $process) {
                    $line = strtok($process->getOutput(), "\n");
                    if ($line && ($ready = json_decode($line, true)) && ($ready['ready'] ?? false)) {
                        $pids[$index] = $ready['pid'];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids, 'Dua worker harus siap; bootstrap error bukan integrity rejection.');
            $this->assertNotSame($pids[0], $pids[1]);
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Dua transaksi independen wajib overlapping dan menunggu lock PostgreSQL.');
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Worker infrastruktur/deadlock/timeout tidak dianggap kalah yang sah. '.$process->getOutput());
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $results[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $failure = collect($results)->firstWhere('ok', false);
        $this->assertSame('integrity', $failure['kind']);
        $this->assertContains($failure['sqlstate'], ['23514', '23505']);
        $allowed = match ($case) {
            'revision-revision' => ['billing_revision_chain'],
            'revision-submit' => ['billing_revision_payment_conflict', 'billing_payment_current_amount'],
            'revision-verify', 'paid-revision' => ['billing_revision_payment_conflict', 'billing_revision_parent_state'],
            'verify-reject' => ['billing_payment_terminal_immutable'],
            'close-successor', 'successor-successor' => ['billing_ownership_close_only', 'billing_ownership_successor', 'billing_ownership_successor_unique', 'billing_ownership_open_unique'],
            'request-key' => ['billing_request_intent_conflict', 'billing_requests_actor_request_unique'],
            'audit-sequence' => ['billing_invoice_payment_state', 'billing_payment_current_amount'],
            'zero-transition' => ['billing_revision_payment_conflict', 'billing_payment_current_amount'],
        };
        $this->assertContains($failure['constraint'], $allowed);
        foreach (array_unique([$g['invoice']['id'], $other['invoice']['id']]) as $id) {
            DB::select('SELECT public.billing_validate_invoice(?)', [$id]);
            $this->assertTrue(DB::table('invoices')->where('id', $id)->exists());
        }
        DB::select('SELECT public.billing_validate_ownership(?)', [$g['p']['elderly']]);
        if ($case === 'request-key') {
            $this->assertSame(1, DB::table('billing_requests')->where('actor_id', $g['p']['admin'])->where('request_id', $key)->count());
            $this->assertSame(3, DB::table('invoice_revisions')->whereIn('invoice_id', [$g['invoice']['id'], $other['invoice']['id']])->count());
        }
        if ($case === 'zero-transition' && DB::table('invoices')->where('id', $g['invoice']['id'])->value('amount') === '0.00') {
            $this->assertSame(0, DB::table('payments')->where('invoice_id', $g['invoice']['id'])->count());
        }
        fwrite(STDOUT, 'BILLING_RACE '.json_encode(['case' => $case, 'pids' => $pids, 'blocked' => $waiting,
            'commits' => 1, 'loser_sqlstate' => $failure['sqlstate'], 'loser_constraint' => $failure['constraint'], 'loser_phase' => $failure['phase']], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
