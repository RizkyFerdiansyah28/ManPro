<?php

// Seluruh mutasi tes dibatasi ke DB acak milik proses ini, termasuk child dan cleanup.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$runId = bin2hex(random_bytes(8));
$database = 'silansia_test_'.$runId;
$token = bin2hex(random_bytes(32));
$cacheDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-phase1-'.$runId;
$cacheFile = $cacheDirectory.DIRECTORY_SEPARATOR.'config.php';
$created = false;
$cacheDirectoryCreated = false;
$guard = null;
$exitCode = 0;

try {
    if (! $app->environment('local', 'testing') || $app->configurationIsCached()) {
        throw new RuntimeException('Runner memerlukan lingkungan local/testing tanpa config cache.');
    }
    if (! mkdir($cacheDirectory, 0700)) {
        throw new RuntimeException('Direktori cache unik tidak dapat dibuat.');
    }
    $cacheDirectoryCreated = true;

    $admin = DB::connection('pgsql');
    $config = $admin->getConfig();
    if ($admin->getDriverName() !== 'pgsql' || ! is_string($config['host']) || $database === $config['database']) {
        throw new RuntimeException('Koneksi administrasi PostgreSQL tidak valid.');
    }
    $server = $admin->selectOne("SELECT current_user AS owner,
        COALESCE(inet_server_addr()::text, '') AS address, COALESCE(inet_server_port()::text, '') AS port");
    if ($server->owner !== $config['username']) {
        throw new RuntimeException('Identitas role PostgreSQL tidak sesuai konfigurasi.');
    }

    // Nama/token hanya berasal dari random_bytes, bukan input bebas.
    $admin->statement('CREATE DATABASE "'.$database.'"');
    $created = true;
    $oid = $admin->selectOne('SELECT oid::text AS oid FROM pg_catalog.pg_database WHERE datname = ?', [$database])->oid;
    $admin->statement('COMMENT ON DATABASE "'.$database.'" IS \'silansia-phase1:'.$token.'\'');
    $identity = [
        'database' => $database, 'oid' => $oid, 'owner' => $server->owner,
        'address' => $server->address, 'port' => $server->port,
        'host' => $config['host'], 'connection_port' => (string) $config['port'],
        'token' => $token, 'cache' => $cacheFile,
    ];
    $guard = new PostgresTestGuard($identity);
    config(['database.connections.silansia_testing' => array_replace($config, [
        'name' => 'silansia_testing', 'url' => null, 'database' => $database,
        'timezone' => 'UTC', 'search_path' => 'public', 'prefix' => '',
    ])]);
    DB::purge('silansia_testing');
    $testDb = DB::connection('silansia_testing');
    $guard->assertTarget($testDb);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'silansia_testing',
        'APP_CONFIG_CACHE' => $cacheFile,
        'SILANSIA_TEST_IDENTITY' => json_encode($identity, JSON_THROW_ON_ERROR),
        'SILANSIA_TEST_DB_DATABASE' => $database,
        'SILANSIA_TEST_DB_HOST' => $config['host'],
        'SILANSIA_TEST_DB_PORT' => (string) $config['port'],
        'SILANSIA_TEST_DB_USERNAME' => $config['username'],
        'SILANSIA_TEST_DB_PASSWORD' => $config['password'],
        'SILANSIA_TEST_DB_SSLMODE' => $config['sslmode'] ?? 'prefer',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    ];
    $run = function (string $action, ?array $expected = null, array $overrides = []) use ($environment, $guard, $testDb): void {
        $guard->assertTarget($testDb);
        $process = new Process([PHP_BINARY, 'tests/Support/postgres-child.php', $action],
            dirname(__DIR__, 2), array_replace($environment, $overrides));
        $process->setTimeout($action === 'phpunit' ? 360 : 180);
        $process->run();
        if ($action === 'phpunit' && $expected === null) {
            echo $process->getOutput();
            echo $process->getErrorOutput();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Suite PostgreSQL gagal.');
            }

            return;
        }
        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result) || ! array_key_exists('ok', $result)) {
            throw new RuntimeException('Protokol child tidak valid; hasil tidak dianggap sebagai guard yang lulus.');
        }
        if ($expected !== null) {
            if ($process->getExitCode() !== 1 || $result !== $expected) {
                throw new RuntimeException('Penolakan child tidak sesuai guard yang diharapkan; kemungkinan kegagalan infrastruktur.');
            }

            return;
        }
        if (! $process->isSuccessful() || $result['ok'] !== true) {
            throw new RuntimeException('Operasi child gagal; detail koneksi disembunyikan.');
        }
        echo $result['output'];
    };
    $mutate = function (callable $operation) use ($guard, $testDb): mixed {
        $guard->assertTarget($testDb);

        return $operation();
    };
    $snapshot = function () use ($guard, $testDb): array {
        $guard->assertTarget($testDb);

        return [
            'columns' => $testDb->select("SELECT table_name, column_name, data_type, column_default, is_nullable
                FROM information_schema.columns WHERE table_schema = 'public'
                AND ((table_name = 'users' AND column_name = 'id') OR (table_name = 'sessions' AND column_name = 'user_id'))
                ORDER BY table_name, column_name"),
            'migrations' => $testDb->table('migrations')->orderBy('id')->get()->all(),
            'users' => $testDb->table('users')->orderBy('id')->get()->all(),
            'sessions' => $testDb->table('sessions')->orderBy('id')->get()->all(),
        ];
    };
    $assertSnapshot = function (array $before) use ($snapshot): void {
        if (serialize($before) !== serialize($snapshot())) {
            throw new RuntimeException('Kolom, catatan migration, atau fixture berubah setelah penolakan/rollback.');
        }
    };

    echo "Database PostgreSQL sementara dibuat dan ownership diverifikasi.\n";
    // Probe sengaja memakai action mutasi, tetapi harus ditolak sebelum perintah dijalankan.
    if (file_put_contents($cacheFile, "<?php throw new RuntimeException('CACHE_MUST_NOT_LOAD');\n") === false) {
        throw new RuntimeException('Fixture cache stale tidak dapat dibuat.');
    }
    try {
        $run('baseline', ['ok' => false, 'reason' => 'safety_rejected', 'guard' => 'cache_present']);
    } finally {
        unlink($cacheFile); // Berkas tepat ini dibuat runner pada direktori acaknya sendiri.
    }
    foreach ([
        ['APP_ENV' => 'local'],
        ['SILANSIA_TEST_DB_DATABASE' => 'not_owned_by_runner'],
        ['SILANSIA_TEST_IDENTITY' => ''],
        ['SILANSIA_TEST_IDENTITY' => json_encode(array_replace($identity, ['oid' => (string) ((int) $oid + 1)]))],
        ['SILANSIA_TEST_IDENTITY' => json_encode(array_replace($identity, ['token' => str_repeat('0', 64)]))],
    ] as $index => $overrides) {
        $reason = ['environment_mismatch', 'connection_mismatch', 'context_missing', 'identity_mismatch', 'identity_mismatch'][$index];
        $run('baseline', ['ok' => false, 'reason' => 'safety_rejected', 'guard' => $reason], $overrides);
    }
    if ($testDb->selectOne("SELECT to_regclass('public.migrations') AS relation")->relation !== null) {
        throw new RuntimeException('Probe safety tidak boleh menjalankan migration.');
    }
    echo "PASS: cache stale, environment, target koneksi, konteks hilang, OID, dan token salah ditolak sebelum mutasi.\n";

    $run('baseline');
    $mutate(fn () => $testDb->table('sessions')->insert([
        'id' => 'anonymous-phase-one-test', 'payload' => 'anonymous-test-payload', 'last_activity' => 1,
    ]));
    $legacyId = $mutate(fn () => $testDb->table('users')->insertGetId([
        'name' => 'Fixture', 'email' => 'fixture@example.test', 'password' => 'test-only',
    ]));
    $expectedGuard = [
        'ok' => false, 'reason' => 'uuid_guard', 'exception' => RuntimeException::class,
        'message' => 'Konversi kunci hanya aman untuk users kosong dan sesi anonim. Diperlukan pemetaan data tersendiri.',
    ];
    $before = $snapshot();
    if (array_column($before['columns'], 'data_type') !== ['bigint', 'bigint']) {
        throw new RuntimeException('Fixture konversi harus dimulai dari kedua kolom bigint.');
    }
    $run('migrate', $expectedGuard);
    $assertSnapshot($before);
    $mutate(fn () => $testDb->table('users')->where('id', $legacyId)->delete());
    echo "PASS: guard users terisi tepat; tipe, migration, dan seluruh fixture tidak berubah.\n";

    $mutate(fn () => $testDb->table('sessions')->where('id', 'anonymous-phase-one-test')->update(['user_id' => 999]));
    $before = $snapshot();
    $run('migrate', $expectedGuard);
    $assertSnapshot($before);
    $mutate(fn () => $testDb->table('sessions')->where('id', 'anonymous-phase-one-test')->update(['user_id' => null]));
    echo "PASS: guard sesi terhubung tepat; tipe, migration, dan seluruh fixture tidak berubah.\n";

    $baseline = $snapshot();
    $run('migrate');
    $run('status');
    $run('phpunit');
    $guard->assertTarget($testDb);
    $domainMigrations = $testDb->table('migrations')->count() - count($baseline['migrations']);
    if ($domainMigrations !== 5) {
        throw new RuntimeException('Jumlah migration domain tidak sesuai Phase 1 dan 4B.');
    }
    foreach (range(1, $domainMigrations) as $step) {
        $run('rollback');
    }
    $assertSnapshot($baseline);
    echo "PASS: rollback lima migration Phase 1/4B, tipe awal, catatan migration, dan seluruh sesi anonim terjaga.\n";
    $run('migrate');
    echo "PASS: seluruh migration Phase 1/4B dapat diterapkan ulang.\n";
} catch (Throwable $exception) {
    echo 'Verifikasi gagal ('.get_class($exception).'). ';
    echo get_class($exception) === RuntimeException::class || get_class($exception) === LogicException::class
        ? $exception->getMessage().PHP_EOL
        : 'Periksa PostgreSQL/CREATEDB; detail koneksi disembunyikan.'.PHP_EOL;
    $exitCode = 1;
} finally {
    DB::purge('silansia_testing');
    if ($created) {
        try {
            if (! $guard instanceof PostgresTestGuard) {
                throw new LogicException('Ownership belum terverifikasi.');
            }
            $guard->assertOwnedForCleanup(DB::connection('pgsql'));
            DB::connection('pgsql')->statement('DROP DATABASE "'.$database.'"');
            echo "Database uji sementara milik runner dihapus.\n";
        } catch (Throwable $exception) {
            echo "Cleanup ditolak/gagal; database uji dipertahankan untuk pemeriksaan manual.\n";
            $exitCode = 1;
        }
    }
    // Tidak ada penghapusan rekursif atau path dari input pengguna.
    if ($cacheDirectoryCreated && is_dir($cacheDirectory) && ! file_exists($cacheFile)) {
        rmdir($cacheDirectory);
    }
}

exit($exitCode);
