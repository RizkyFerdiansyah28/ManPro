<?php

namespace Tests\Feature\Residents;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\FinancialOwnershipFeatureTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('financial-ownership-ledger')]
class FinancialOwnershipConcurrencyTest extends FinancialOwnershipFeatureTestCase
{
    private array $processes = [];

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
        return [['same-target'], ['stale-expected'], ['ledger-successor'], ['create-ledger-failure'], ['different-targets'],
            ['repeated-transfer'], ['deactivation-first'], ['transfer-before-deactivation'], ['enrollment-close']];
    }

    private function startWorker(array $payload): void
    {
        $process = new Process([PHP_BINARY, 'tests/Support/financial-ownership-worker.php', json_encode($payload, JSON_THROW_ON_ERROR)], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true',
                'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-test-only-approval']);
        $process->setTimeout(40);
        $process->start();
        $this->processes[] = $process;
    }

    private function readiness(int $count): array
    {
        $deadline = microtime(true) + 15;
        do {
            $ready = [];
            foreach ($this->processes as $index => $process) {
                $first = strtok($process->getOutput(), "\n");
                $message = $first ? json_decode($first, true) : null;
                if ($message['ready'] ?? false) {
                    $ready[$index] = $message;
                }
            }
            if (count($ready) === $count) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertCount($count, $ready, 'Worker harus siap; error bootstrap bukan konflik bisnis.');

        return $ready;
    }

    private function waits(array $pids): void
    {
        $deadline = microtime(true) + 15;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $count = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
            if ($count === count($pids)) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertSame(count($pids), $count, 'Overlap harus dibuktikan oleh lock waits PostgreSQL.');
    }

    #[DataProvider('races')]
    public function test_real_concurrent_writers_preserve_atomic_ownership_and_existing_domain_rules(string $case): void
    {
        $p = $this->people();
        $resident = $case === 'create-ledger-failure' ? null : $this->resident($p);
        $root = $resident ? DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->sole() : null;
        $base = ['admin_id' => $p['admin']->id, 'old_family_id' => $p['old']->id, 'next_family_id' => $p['next']->id,
            'other_family_id' => $p['other']->id, 'resident_id' => $resident?->id, 'root_period_id' => $root?->id,
            'expected_family_id' => $p['old']->id, 'operation' => 'transfer'];
        $payloads = [$base, $base];
        if ($case === 'different-targets') {
            $payloads[1]['next_family_id'] = $p['other']->id;
        } elseif ($case === 'stale-expected') {
            $payloads[1]['expected_family_id'] = $p['other']->id;
        } elseif ($case === 'ledger-successor') {
            $payloads[1]['operation'] = 'append_successor';
        } elseif ($case === 'create-ledger-failure') {
            $names = ['Ledger race '.bin2hex(random_bytes(8)), 'Ledger fail '.bin2hex(random_bytes(8))];
            $this->fault('root', $names[1]);
            $payloads[0] = [...$base, 'operation' => 'create', 'name' => $names[0]];
            $payloads[1] = [...$base, 'operation' => 'create', 'name' => $names[1]];
        } elseif (in_array($case, ['deactivation-first', 'transfer-before-deactivation'], true)) {
            $payloads = $case === 'deactivation-first' ? [[...$base, 'operation' => 'deactivate'], $base]
                : [$base, [...$base, 'operation' => 'deactivate']];
        } elseif ($case === 'enrollment-close') {
            (new ServiceClassSeeder)->run();
            $enrollment = ServiceEnrollment::create(['elderly_id' => $resident->id,
                'service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
                'monthly_price' => '3100.00', 'started_at' => now()->subDay()]);
            $payloads[1] = [...$base, 'operation' => 'close_enrollment', 'enrollment_id' => $enrollment->id];
        }
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        DB::beginTransaction();
        try {
            if ($case === 'transfer-before-deactivation') {
                DB::table('elderlies')->where('id', $resident->id)->lockForUpdate()->first();
            } else {
                DB::table('users')->where('id', $p['admin']->id)->lockForUpdate()->first();
            }
            $this->startWorker($payloads[0]);
            if (in_array($case, ['deactivation-first', 'transfer-before-deactivation'], true)) {
                $first = $this->readiness(1);
                $this->waits([$first[0]['pid']]);
            }
            $this->startWorker($payloads[1]);
            $ready = $this->readiness(2);
            $pids = array_column($ready, 'pid');
            $this->assertNotSame($pids[0], $pids[1]);
            $this->waits($pids);
            $released = DB::scalar('SELECT clock_timestamp()::text');
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Infrastructure/deadlock/timeout bukan hasil bisnis yang sah: '.$process->getOutput());
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $results[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        }
        $expectedCommits = in_array($case, ['transfer-before-deactivation', 'enrollment-close'], true) ? 2 : 1;
        $this->assertCount($expectedCommits, array_filter($results, fn ($r) => $r['ok']));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $failure) {
            if (in_array($case, ['ledger-successor', 'create-ledger-failure'], true)) {
                $this->assertSame('integrity', $failure['kind']);
                $this->assertSame('23514', $failure['sqlstate']);
                $this->assertSame($case === 'ledger-successor' ? 'billing_ownership_successor' : 'test_ledger_fault', $failure['constraint']);
            } else {
                $this->assertSame('validation', $failure['kind']);
                $this->assertSame(['family_id'], $failure['fields']);
                $this->assertNull($failure['sqlstate']);
            }
        }
        if ($case === 'create-ledger-failure') {
            $this->assertSame(1, DB::table('elderlies')->whereIn('name', $names)->count());
            $resident = Elderly::where('name', $names[0])->sole();
        }
        $periods = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->get();
        $leaf = $periods->whereNull('effective_to')->sole();
        $this->assertSame($resident->fresh()->family_id, $leaf->family_id);
        $this->assertSame(in_array($case, ['create-ledger-failure', 'deactivation-first'], true) ? 1 : 2, $periods->count());
        if ($case !== 'deactivation-first') {
            $this->assertTrue(DB::scalar('SELECT ?::timestamptz >= ?::timestamptz', [$leaf->recorded_at, $released]));
        }
        DB::select('SELECT public.billing_validate_ownership(?)', [$resident->id]);
        if ($case === 'enrollment-close') {
            $this->assertNotNull($enrollment->fresh()->ended_at);
        }
        if (in_array($case, ['deactivation-first', 'transfer-before-deactivation'], true)) {
            $this->assertFalse($p['next']->fresh()->is_active);
        }
        if ($case === 'repeated-transfer') {
            $before = $this->snapshot();
            try {
                $this->transfer($p['admin'], $resident, $p['next'], $p['old']->id);
                $this->fail('Repeated request dengan expected owner lama harus ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('family_id', $exception->errors());
            }
            $this->assertSame($before, $this->snapshot());
        }
        fwrite(STDOUT, 'LEDGER_RACE '.json_encode(['case' => $case, 'pids' => $pids, 'blocked' => 2,
            'commits' => $expectedCommits, 'errors' => array_values(array_filter($results, fn ($r) => ! $r['ok']))], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
