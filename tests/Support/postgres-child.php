<?php

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\TextUI\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    // Dilakukan sebelum bootstrap: cache yang ada tidak pernah di-require.
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $action = $argv[1] ?? '';
    if (in_array($action, ['phpunit', 'schema_phpunit', 'integrity_phpunit', 'ledger_phpunit', 'issuance_phpunit', 'concurrency_phpunit', 'temporal_phpunit', 'http_phpunit'], true)) {
        // Preflight koneksi tanpa bootstrap/error handler Laravel sebelum PHPUnit.
        $connection = (new ConnectionFactory(new Container))->make([
            'driver' => 'pgsql', 'url' => null,
            'host' => getenv('SILANSIA_TEST_DB_HOST'), 'port' => getenv('SILANSIA_TEST_DB_PORT'),
            'database' => getenv('SILANSIA_TEST_DB_DATABASE'), 'username' => getenv('SILANSIA_TEST_DB_USERNAME'),
            'password' => getenv('SILANSIA_TEST_DB_PASSWORD'), 'charset' => 'utf8',
            'prefix' => '', 'search_path' => 'public', 'sslmode' => getenv('SILANSIA_TEST_DB_SSLMODE'),
            'timezone' => 'UTC',
        ], 'silansia_testing');
        $guard->assertTarget($connection);
        $connection->disconnect();
        // Tiap kasus memverifikasi app/koneksi aktual lagi sebelum transaksi fixture.
        $selection = match ($action) {
            'schema_phpunit' => ['--group=billing-expansion-schema'],
            'integrity_phpunit' => ['--group=billing-integrity'],
            'ledger_phpunit' => ['--group=financial-ownership-ledger'],
            'issuance_phpunit' => ['--group=invoice-issuance'],
            'concurrency_phpunit' => ['--group=invoice-concurrency'],
            'temporal_phpunit' => ['--group=invoice-temporal'],
            'http_phpunit' => ['--group=invoice-http'],
            default => ['--exclude-group=billing-expansion-schema', '--exclude-group=billing-integrity', '--exclude-group=financial-ownership-ledger', '--exclude-group=invoice-issuance', '--exclude-group=invoice-concurrency', '--exclude-group=invoice-temporal', '--exclude-group=invoice-http'],
        };
        $exitCode = (new Application)->run([
            'phpunit', '--configuration='.(getenv('SILANSIA_P93D_VERIFY') === '1' ? 'phpunit.p93d.xml' : 'phpunit.pgsql.xml'), '--do-not-cache-result',
            '--fail-on-risky', '--fail-on-warning', '--fail-on-skipped', '--fail-on-empty-test-suite', ...$selection,
        ]);
        exit($exitCode);
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $guard->assertSafe($app, DB::connection('silansia_testing'));
    $options = ['--database' => 'silansia_testing', '--no-interaction' => true, '--no-ansi' => true];
    $command = match ($action) {
        'baseline', 'expansion', 'migrate' => 'migrate',
        'rollback' => 'migrate:rollback',
        'status' => 'migrate:status',
        default => throw new LogicException('action_invalid'),
    };
    if ($action !== 'status') {
        $options['--force'] = true;
    }
    if ($action === 'baseline') {
        $options['--path'] = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/0001_01_01_000001_create_cache_table.php',
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
        ];
    }
    if ($action === 'expansion') {
        $options['--path'] = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn (string $path): bool => basename($path) !== '2026_11_13_000009_add_billing_integrity_guards.php'));
        $options['--realpath'] = true;
    }
    if ($action === 'rollback') {
        $options['--step'] = 1;
    }

    // Guard dan operasi berjalan pada proses/koneksi yang sama, tanpa subprocess artisan.
    $guard->assertSafe($app, DB::connection('silansia_testing'));
    $output = new BufferedOutput;
    $exitCode = $kernel->call($command, $options, $output);
    if ($exitCode !== 0) {
        throw new RuntimeException('Perintah child gagal.');
    }
    echo json_encode(['ok' => true, 'action' => $action, 'output' => $output->fetch()], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $guardMessage = 'Konversi kunci hanya aman untuk users kosong dan sesi anonim. Diperlukan pemetaan data tersendiri.';
    if (get_class($exception) === RuntimeException::class && $exception->getMessage() === $guardMessage) {
        $result = ['ok' => false, 'reason' => 'uuid_guard', 'exception' => RuntimeException::class, 'message' => $guardMessage];
    } elseif (get_class($exception) === RuntimeException::class && $exception->getMessage() === 'Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.') {
        $result = ['ok' => false, 'reason' => 'billing_history_guard', 'exception' => RuntimeException::class, 'message' => $exception->getMessage()];
    } elseif (get_class($exception) === LogicException::class) {
        $result = ['ok' => false, 'reason' => 'safety_rejected', 'guard' => $exception->getMessage()];
    } else {
        // Tidak mencetak SQL, konfigurasi, atau pesan exception koneksi.
        $result = ['ok' => false, 'reason' => 'infrastructure_failure', 'exception' => get_class($exception)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
