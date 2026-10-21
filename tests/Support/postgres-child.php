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
    if ($action === 'phpunit') {
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
        $exitCode = (new Application)->run([
            'phpunit', '--configuration=phpunit.pgsql.xml', '--do-not-cache-result',
            '--fail-on-risky', '--fail-on-warning',
        ]);
        exit($exitCode);
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $guard->assertSafe($app, DB::connection('silansia_testing'));
    $options = ['--database' => 'silansia_testing', '--no-interaction' => true, '--no-ansi' => true];
    $command = match ($action) {
        'baseline', 'migrate' => 'migrate',
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
    } elseif (get_class($exception) === LogicException::class) {
        $result = ['ok' => false, 'reason' => 'safety_rejected', 'guard' => $exception->getMessage()];
    } else {
        // Tidak mencetak SQL, konfigurasi, atau pesan exception koneksi.
        $result = ['ok' => false, 'reason' => 'infrastructure_failure', 'exception' => get_class($exception)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
