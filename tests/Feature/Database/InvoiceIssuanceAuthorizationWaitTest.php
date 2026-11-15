<?php

namespace Tests\Feature\Database;

use App\Services\Billing\InvoiceIssuanceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceIssuanceFeatureTestCase;

#[Group('invoice-issuance')]
class InvoiceIssuanceAuthorizationWaitTest extends InvoiceIssuanceFeatureTestCase
{
    public static function actorChanges(): array
    {
        return [['deactivate'], ['role'], ['deactivate_replay'], ['timeout']];
    }

    #[DataProvider('actorChanges')]
    public function test_authorization_is_rechecked_after_real_postgres_user_lock_wait(string $case): void
    {
        $s = $this->source();
        $command = $this->command($s);
        if ($case === 'deactivate_replay') {
            app(InvoiceIssuanceService::class)->issue($command);
        }
        $financialBefore = $this->fingerprints(['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'payments']);
        DB::beginTransaction();
        DB::table('users')->where('id', $command->actorId)->lockForUpdate()->first();
        $process = new Process([PHP_BINARY, 'tests/Support/invoice-issuance-worker.php', json_encode($command, JSON_THROW_ON_ERROR)], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true',
                'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-test-only-approval', 'INVOICE_ISSUANCE_ENABLED' => 'true']);
        $process->setTimeout(30);
        $process->start();
        try {
            $deadline = microtime(true) + 10;
            $ready = null;
            do {
                $first = strtok($process->getOutput(), "\n");
                $ready = $first ? json_decode($first, true) : null;
                if ($ready['ready'] ?? false) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($ready['ready'] ?? false, 'Bootstrap gagal bukan penolakan bisnis.');
            $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $ready['pid']);
            $deadline = microtime(true) + 4;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->where('pid', $ready['pid'])->where('wait_event_type', 'Lock')->exists();
                if ($blocked) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Wajib ada PostgreSQL lock wait sebelum role/status diubah.');
            if ($case !== 'timeout') {
                DB::table('users')->where('id', $command->actorId)->update($case === 'role' ? ['role' => 'perawat'] : ['is_active' => false]);
                DB::commit();
            }
            $process->wait();
            $messages = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", trim($process->getOutput()))));
            $this->assertSame($case === 'timeout' ? 1 : 0, $process->getExitCode(), $process->getErrorOutput());
            $expected = $case === 'timeout' ? ['ok' => false, 'kind' => 'lock_timeout', 'sqlstate' => '55P03', 'rule' => null]
                : ['ok' => false, 'kind' => 'unauthorized', 'sqlstate' => null, 'rule' => null];
            $this->assertSame($expected, end($messages));
            if ($case === 'timeout') {
                DB::rollBack();
            }
            $this->assertEquals($financialBefore, $this->fingerprints(['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'payments']));
            fwrite(STDOUT, 'ISSUANCE_AUTH_WAIT '.json_encode(['case' => $case, 'pid' => $ready['pid'], 'blocked' => true, 'outcome' => $expected['kind']], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
