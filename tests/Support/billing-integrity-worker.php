<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\FrameworkRuntimeIsolation;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    FrameworkRuntimeIsolation::attach($app);
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (! in_array($data['operation'] ?? null, ['revise', 'zero', 'submit', 'verify', 'reject', 'transfer', 'append_only', 'retry', 'cancel'], true)) {
        throw new LogicException('operation_invalid');
    }
    $fixture = new class
    {
        use BillingIntegrityFixtures;

        public function execute(array $data): void
        {
            $g = $data['graph'];
            $this->billingLocks($g);
            switch ($data['operation']) {
                case 'revise': $this->billingRevise($g, $data['amount'] ?? '2200.00', $data['request_id'] ?? null);
                    break;
                case 'zero': $this->billingRevise($g, '0.00');
                    break;
                case 'submit': $this->billingSubmit($g);
                    break;
                case 'verify': $this->billingReview($g, 'verified');
                    break;
                case 'reject': $this->billingReview($g, 'rejected');
                    break;
                case 'transfer': $this->billingTransfer($g);
                    break;
                case 'append_only':
                    DB::table('financial_ownership_periods')->insert([...$g['period'], 'id' => $this->billingId(),
                        'family_id' => $g['p']['next_family'], 'previous_period_id' => $g['period']['id'], 'source' => 'family_transfer',
                        'effective_from' => '2026-11-05 00:00:00.123456+07', 'recorded_at' => '2026-11-05 00:00:00.123456+07']);
                    break;
                case 'retry': DB::table('billing_requests')->insertOrIgnore([...$g['request'], 'id' => $this->billingId()]);
                    break;
                case 'cancel':
                    $at = '2026-11-04 00:00:00.123456+07';
                    DB::table('invoices')->where('id', $g['invoice']['id'])->update(['status' => 'cancelled', 'cancelled_by' => $g['p']['admin'],
                        'cancelled_at' => $at, 'cancellation_reason' => 'Fixture race cancellation']);
                    $q = $this->billingRequest($g, 'cancel', $g['p']['admin'], $g['revision']['id']);
                    $this->billingAction($g, $q, 'invoice_cancelled', $g['revision']['id'], null, $at);
                    break;
            }
        }
    };
    echo json_encode(['ready' => true, 'pid' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR).PHP_EOL;
    fflush(STDOUT);
    $guard->assertSafe($app, DB::connection());
    $callbackDone = false;
    DB::transaction(function () use ($fixture, $data, &$callbackDone) {
        DB::statement("SET LOCAL lock_timeout = '20s'");
        DB::statement("SET LOCAL statement_timeout = '25s'");
        $fixture->execute($data);
        $callbackDone = true;
    });
    echo json_encode(['ok' => true, 'phase' => 'commit'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PDOException $exception) {
    $state = $exception->errorInfo[0] ?? '';
    if (! in_array($state, ['23514', '23505', '23503', '23001'], true)) {
        echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'sqlstate' => $state], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
    preg_match('/Integritas billing ditolak \((billing_[a-z_]+)\)|constraint "([a-z_]+)"/', $exception->errorInfo[2], $matches);
    echo json_encode(['ok' => false, 'kind' => 'integrity', 'sqlstate' => $state,
        'constraint' => ($matches[1] ?? '') ?: ($matches[2] ?? ''), 'phase' => ($callbackDone ?? false) ? 'commit' : 'write'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => $exception::class], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
