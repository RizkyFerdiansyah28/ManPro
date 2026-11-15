<?php

use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    if (config('financial_ownership.enabled') !== true || config('invoice_issuance.enabled') !== true
        || config('financial_ownership.cutover_reference') !== 'owned-test-only-approval') {
        throw new LogicException('test_gate_context_invalid');
    }
    $input = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    $command = new InvoiceIssueCommand($input['actorId'], $input['elderlyId'], $input['billingMonth'], $input['requestId'],
        new InvoiceIssueConfirmation($input['confirmation']['fingerprint']));
    echo json_encode(['ready' => true, 'pid' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR).PHP_EOL;
    fflush(STDOUT);
    $result = app(InvoiceIssuanceService::class)->issue($command);
    echo json_encode(['ok' => true, 'decision' => $result->decision->value, 'replayed' => $result->replayed], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (InvoiceIssuanceFailure $failure) {
    echo json_encode(['ok' => false, 'kind' => $failure->kind, 'sqlstate' => $failure->sqlState, 'rule' => $failure->rule], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($failure->kind !== 'unauthorized') {
        exit(1); // Infrastructure/readiness/timeout bukan expected authorization loser.
    }
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => $exception::class], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
