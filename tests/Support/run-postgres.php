<?php

// Seluruh mutasi tes dibatasi ke DB acak milik proses ini, termasuk child dan cleanup.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FrameworkRuntimeIsolation;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    FrameworkRuntimeIsolation::environment();
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'reason' => $error instanceof LogicException ? $error->getMessage() : 'runtime_preflight_failure']);
    exit(78);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
FrameworkRuntimeIsolation::attach($app);
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
    $integrityOnly = ($argv[1] ?? '') === '--integrity-only';
    $concurrencyOnly = ($argv[1] ?? '') === '--concurrency-only';
    $temporalOnly = ($argv[1] ?? '') === '--temporal-only';
    $httpOnly = ($argv[1] ?? '') === '--http-only';
    $paymentUiOnly = ($argv[1] ?? '') === '--payment-ui-only';
    $paymentHttpOnly = ($argv[1] ?? '') === '--payment-http-only';
    $paymentOnly = ($argv[1] ?? '') === '--payment-only' || $paymentHttpOnly;
    $issuanceOnly = ($argv[1] ?? '') === '--issuance-only' || $concurrencyOnly || $temporalOnly || $httpOnly || $paymentOnly || $paymentUiOnly;
    if (count($argv) > 2 || (isset($argv[1]) && ! $integrityOnly && ! $issuanceOnly)) {
        throw new RuntimeException('Runner menerima mode penuh, --integrity-only, --issuance-only, --concurrency-only, --temporal-only, atau --http-only.');
    }
    if (! $app->environment('local', 'testing') || $app->configurationIsCached()) {
        throw new RuntimeException('Runner memerlukan lingkungan local/testing tanpa config cache.');
    }
    if (! mkdir($cacheDirectory, 0700)) {
        throw new RuntimeException('Direktori cache unik tidak dapat dibuat.');
    }
    $cacheDirectoryCreated = true;

    if (getenv('SILANSIA_P93D_VERIFY') === '1') {
        $neutral = config('database.connections.pgsql');
        if ($neutral['database'] !== 'postgres' || ! empty($neutral['url'])
            || $neutral['host'] !== '127.0.0.1' || (string) $neutral['port'] !== '5432') {
            throw new RuntimeException('P9-3D menolak koneksi administrasi selain postgres pada loopback.');
        }
    }
    $admin = DB::connection('pgsql');
    $config = $admin->getConfig();
    if ($admin->getDriverName() !== 'pgsql' || ! is_string($config['host']) || $database === $config['database']) {
        throw new RuntimeException('Koneksi administrasi PostgreSQL tidak valid.');
    }
    $server = $admin->selectOne("SELECT current_database() AS database, current_user AS owner,
        COALESCE(inet_server_addr()::text, '') AS address, COALESCE(inet_server_port()::text, '') AS port");
    if ($server->owner !== $config['username']) {
        throw new RuntimeException('Identitas role PostgreSQL tidak sesuai konfigurasi.');
    }
    if (getenv('SILANSIA_P93D_VERIFY') === '1') {
        if ($server->database !== 'postgres') {
            throw new RuntimeException('P9-3D administrative identity mismatch.');
        }
        echo "ADMIN_TARGET: postgres pada 127.0.0.1:5432 terverifikasi; bukan database development.\n";
    }
    if ($admin->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname ~ '^silansia_test_[a-f0-9]{16}$'") !== 0) {
        throw new RuntimeException('Terdapat sesi test lama/runner lain. Tidak ada proses atau database lama yang dibersihkan.');
    }
    echo "PASS: tidak ada sesi database test lama pada server ini.\n";

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
    if (FrameworkRuntimeIsolation::active()) {
        $identity['runtime_root'] = FrameworkRuntimeIsolation::root();
    }
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
        'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => '',
        'INVOICE_ISSUANCE_ENABLED' => 'false', 'PAYMENT_CORE_ENABLED' => 'false',
        'SILANSIA_P92D_EVIDENCE_FILE' => base_path('docs/report/SILANSIA_P9_2D_TEST_EVIDENCE_'.$runId.'.jsonl'),
        'SILANSIA_P92D1_EVIDENCE_FILE' => base_path('docs/report/SILANSIA_P9_2D1_TEST_EVIDENCE_'.$runId.'.jsonl'),
        'SILANSIA_P94C_EVIDENCE_FILE' => base_path('docs/report/SILANSIA_P9_4C_TEST_EVIDENCE_'.$runId.'.jsonl'),
        'SILANSIA_P93B_EVIDENCE_FILE' => base_path('docs/report/SILANSIA_P9_3B_TEST_EVIDENCE_'.$runId.'.jsonl'),
    ];
    $run = function (string $action, ?array $expected = null, array $overrides = []) use ($environment, $guard, $testDb): void {
        $guard->assertTarget($testDb);
        $process = new Process([PHP_BINARY, 'tests/Support/postgres-child.php', $action],
            dirname(__DIR__, 2), array_replace($environment, $overrides));
        $process->setTimeout(in_array($action, ['phpunit', 'schema_phpunit', 'integrity_phpunit', 'ledger_phpunit', 'issuance_phpunit', 'concurrency_phpunit', 'temporal_phpunit', 'http_phpunit', 'payment_phpunit', 'payment_http_phpunit', 'payment_ui_phpunit'], true) ? 600 : 180);
        $process->run();
        if (in_array($action, ['phpunit', 'schema_phpunit', 'integrity_phpunit', 'ledger_phpunit', 'issuance_phpunit', 'concurrency_phpunit', 'temporal_phpunit', 'http_phpunit', 'payment_phpunit', 'payment_http_phpunit', 'payment_ui_phpunit'], true) && $expected === null) {
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
    $run('expansion');
    echo "TEST_STAGE: schema P9-1A dan preflight P9-1B\n";
    if (! $issuanceOnly) {
        $run('schema_phpunit');
    } else {
        echo "NOT RUN: schema suite (mode targeted; safety probes dan migration tetap dijalankan).\n";
    }
    $run('migrate');
    $run('status');
    if (! $integrityOnly && ! $issuanceOnly) {
        echo "TEST_STAGE: regresi existing dengan guard P9-1B aktif\n";
        $run('phpunit');
    } else {
        echo "NOT RUN: regresi existing (mode targeted; bukan hasil regresi penuh).\n";
    }
    $guard->assertTarget($testDb);
    $domainMigrations = $testDb->table('migrations')->count() - count($baseline['migrations']);
    $expectedDomainMigrations = [
        '2026_10_21_000001_convert_user_keys_to_uuid',
        '2026_10_21_000002_create_silansia_foundation',
        '2026_10_21_000003_reject_nan_monetary_values',
        '2026_10_24_000004_create_camera_operating_periods',
        '2026_10_24_000005_link_ai_detections_to_camera_periods',
        '2026_11_10_000006_add_incident_actions_and_precision',
        '2026_11_10_000007_harden_staff_notifications',
        '2026_11_12_000008_expand_billing_schema',
        '2026_11_13_000009_add_billing_integrity_guards',
    ];
    $actualDomainMigrations = $testDb->table('migrations')->whereIn('migration', $expectedDomainMigrations)
        ->orderBy('migration')->pluck('migration')->all();
    if ($domainMigrations !== count($expectedDomainMigrations) || $actualDomainMigrations !== $expectedDomainMigrations) {
        throw new RuntimeException('Inventaris migration domain tidak sesuai Phase 1/4B/6/7/P9-1A.');
    }
    foreach (range(1, $domainMigrations) as $step) {
        $run('rollback');
    }
    $assertSnapshot($baseline);
    echo "PASS: rollback sembilan migration Phase 1/4B/6/7/P9-1A/P9-1B kosong, tipe awal, catatan migration, dan seluruh sesi anonim terjaga.\n";
    $run('migrate');
    echo "PASS: seluruh migration Phase 1/4B/6/7/P9-1A/P9-1B dapat diterapkan ulang.\n";
    // Histori immutable suite integrity sengaja dipertahankan sampai owned DB dihapus.
    if (! $issuanceOnly) {
        echo "TEST_STAGE: integrity dan concurrency P9-1B\n";
        $run('integrity_phpunit');
        echo "TEST_STAGE: integrasi writer dan concurrency P9-1C (gate ON hanya di fixture owned DB)\n";
        $run('ledger_phpunit');
    } else {
        echo "NOT RUN: integrity/ledger suites (mode targeted).\n";
    }
    if (! $concurrencyOnly && ! $temporalOnly && ! $httpOnly && ! $paymentOnly && ! $paymentUiOnly) {
        echo "TEST_STAGE: internal invoice issuance P9-2C (gate ON hanya di fixture owned DB)\n";
        $run('issuance_phpunit');
    } else {
        echo "NOT RUN: issuance C (mode targeted concurrency/temporal).\n";
    }
    if ($concurrencyOnly || ! $issuanceOnly) {
        echo "TEST_STAGE: concurrency/integration P9-2D\n";
        $run('concurrency_phpunit');
    } else {
        echo "NOT RUN: concurrency D (mode --issuance-only).\n";
    }
    if ($temporalOnly || ! $issuanceOnly) {
        echo "TEST_STAGE: temporal coverage P9-2D.1\n";
        $run('temporal_phpunit');
    } else {
        echo "NOT RUN: temporal D.1 (mode targeted lain).\n";
    }
    if ($httpOnly || ! $issuanceOnly) {
        echo "TEST_STAGE: Admin invoice HTTP P9-3B\n";
        $run('http_phpunit');
    } else {
        echo "NOT RUN: HTTP P9-3B (mode targeted lain).\n";
    }
    if ($paymentOnly || ! $issuanceOnly) {
        echo "TEST_STAGE: internal payment core P9-4B (owned fixture only)\n";
        $run('payment_phpunit');
    }
    if ($paymentHttpOnly || ! $issuanceOnly) {
        echo "TEST_STAGE: payment HTTP/private storage P9-4C (synthetic owned fixtures only)\n";
        $run('payment_http_phpunit');
    }
    $guard->assertTarget($testDb);
    if ($paymentUiOnly || (getenv('SILANSIA_P94D_VERIFY') === '1' && ! $issuanceOnly)) {
        $run('payment_ui_phpunit');
    }
    $financialSnapshot = function () use ($guard, $testDb): array {
        $guard->assertTarget($testDb);
        $result = [];
        foreach (['invoices', 'payments', 'financial_ownership_periods', 'invoice_revisions', 'billing_requests', 'billing_actions', 'migrations'] as $table) {
            $result[$table] = $testDb->selectOne('SELECT count(*)::int AS count, md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text), \'[]\'::jsonb)::text) AS fingerprint FROM public."'.$table.'" t');
        }

        return $result;
    };
    $historyBefore = $financialSnapshot();
    if ($historyBefore['invoice_revisions']->count < 1 || $historyBefore['financial_ownership_periods']->count < 1) {
        throw new RuntimeException('Suite integrity wajib meninggalkan histori committed untuk probe rollback.');
    }
    $run('rollback', ['ok' => false, 'reason' => 'billing_history_guard', 'exception' => RuntimeException::class,
        'message' => 'Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.']);
    if (serialize($historyBefore) !== serialize($financialSnapshot())) {
        throw new RuntimeException('Histori atau catatan migration berubah setelah rollback guard ditolak.');
    }
    echo "PASS: rollback guard pada histori committed ditolak tepat; seluruh histori dan migration records terjaga.\n";
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
    if ($cacheDirectoryCreated && is_dir($cacheDirectory) && ! file_exists($cacheFile) && count(scandir($cacheDirectory)) === 2) {
        rmdir($cacheDirectory);
    }
}

exit($exitCode);
