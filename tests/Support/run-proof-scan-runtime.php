<?php

declare(strict_types=1);

// Dedicated IMP01 runtime harness. No web server, worker or application writer modifications.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\Billing\InvoiceSchemaContract;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\ProofScanSchemaContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\AcademicProofScanWorkerCases;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentScannerGraphCases;
use Tests\Support\PostgresTestGuard;

$stage = 'preflight';
$created = false;
$identity = null;
$guard = null;
$results = [];
$proofAccess = ($argv[1] ?? '') === '--proof-access';
$proofClosure = $proofAccess && getenv('SILANSIA_PROOF_ACCESS_CLOSURE') === '1';
$accessCase = $proofAccess ? ($argv[2] ?? null) : null;
$preflightOnly = ($argv[1] ?? '') === '--neutral-preflight' || $accessCase === 'preflight';
$continuation = ($argv[1] ?? '') === '--continuation';
$paymentWriter = ($argv[1] ?? '') === '--payment-writer' || $accessCase === 'writer';
$technicalRegression = ($argv[1] ?? '') === '--technical-regression' || $accessCase === 'technical';
$academic = ($argv[1] ?? '') === '--academic-case' || ($accessCase !== null && str_starts_with($accessCase, 'academic-'));
$academicCase = $academic ? ($proofAccess ? substr($accessCase, 9) : ($argv[2] ?? '')) : null;
$imp03Evidence = $academic || (($paymentWriter || $technicalRegression) && ($argv[2] ?? '') === '--imp03-evidence');
$writerValidation = $paymentWriter || $technicalRegression || $academic || $proofAccess;
if (count($argv) > ($imp03Evidence || $proofAccess ? 3 : 2)
    || (isset($argv[1]) && ! $preflightOnly && ! $continuation && ! $writerValidation)
    || ($proofAccess && ! in_array($accessCase, ['preflight', 'positive', 'technical', 'writer', 'academic-clean', 'academic-unknown',
        'academic-infected', 'academic-stale_envelopes', 'academic-infected_rescan_hold', 'academic-concurrentClaim'], true))
    || ($academic && ! in_array($academicCase, [...AcademicProofScanWorkerCases::CASES, 'concurrentClaim'], true))) {
    fwrite(STDERR, "Mode runner tidak dikenal.\n");
    exit(78);
}
$run = bin2hex(random_bytes(8));
$accessEvidence = $proofClosure ? 'Runtime-Closure' : (getenv('SILANSIA_PROOF_ACCESS_RETEST') === '1' ? 'Runtime-Retest' : 'Runtime');
$evidenceDirectory = $proofAccess ? '/docs/report/P9/P9-4G/R2-Scanner-Quarantine/IMP-R2-04/'.$accessEvidence.'/evidence/' : ($imp03Evidence ? '/docs/report/P9/P9-4G/R2-Scanner-Quarantine/IMP-R2-03/Runtime/evidence/' : ($writerValidation
    ? '/docs/report/P9/P9-4G/R2-Scanner-Quarantine/IMP-R2-02/Runtime/evidence/'
    : '/docs/report/P9/P9-4G/R2-Scanner-Quarantine/IMP-R2-01-Runtime/'
        .($preflightOnly ? 'Preflight-Recovery/evidence/' : ($continuation ? 'Continuation/evidence/' : 'evidence/'))));
$evidence = dirname(__DIR__, 2).$evidenceDirectory.'RUN_'.$run.'.json';
$record = ['run' => $run, 'started_at' => gmdate('c'), 'php' => PHP_VERSION, 'tests' => [], 'cleanup' => 'NOT CREATED'];
$record['preflight_only'] = $preflightOnly;
$record['mode'] = $argv[1] ?? 'IMP01';
$record['academic_case'] = $academicCase;
$record['proof_access_case'] = $accessCase;
$academicEvidence = static function (string $key, mixed $value) use (&$record): void {
    $record['academic'][$key][] = $value;
};
$assert = static function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
try {
    Runtime::environment();
    Runtime::assertProtected();
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(Runtime::root().'/environment');
    $app->loadEnvironmentFrom('absent.env');
    Runtime::attach($app);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $record['runtime'] = Runtime::resolved($app);
    $assert($app['db']->getConnections() === [], 'bootstrap_opened_database');
    $assert($app->environment('testing') && ! $app->configurationIsCached(), 'testing_required');
    if ($proofAccess) {
        $record['child_config_checks'] = ['testing' => getenv('APP_ENV') === 'testing',
            'connection_environment' => getenv('DB_CONNECTION') === 'silansia_testing',
            'connection_default' => config('database.default') === 'silansia_testing',
            'target_undefined' => config('database.connections.silansia_testing.driver') === 'pgsql'
                && config('database.connections.silansia_testing.host') === '127.0.0.1'
                && (string) config('database.connections.silansia_testing.port') === '5432'
                && config('database.connections.silansia_testing.url') === null
                && config('database.connections.silansia_testing.database') === '',
            'fallback_memory' => getenv('DB_DATABASE') === ':memory:',
            'url_absent' => in_array(getenv('DB_URL'), ['', false], true)
                && in_array(config('database.connections.pgsql.url'), ['', null], true)];
        $assert(! in_array(false, $record['child_config_checks'], true), 'proof_access_child_identity');
        $assert(is_string(config('app.key')) && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', config('app.key')) === 1
            && getenv('APP_KEY') === config('app.key') && empty(config('app.previous_keys')), 'proof_access_synthetic_key_required');
        $assert($accessCase !== 'positive' || is_file(__DIR__.'/proof-scan-access-runtime-case.php'), 'proof_access_cases_required');
        $assert(! $proofClosure || $accessCase !== 'positive' || is_file(__DIR__.'/proof-scan-access-runtime-closure.php'),
            'proof_closure_cases_required');
        $record['child_preconnect'] = ['environment' => 'testing', 'default' => 'silansia_testing', 'cache_isolated' => true,
            'no_connections' => true, 'synthetic_key_present' => true, 'no_database_target_before_create' => true];
    }
    // Parse credentials in memory only; never load development DB name, URL or connection.
    $environment = Dotenv\Dotenv::parse(file_get_contents(base_path('.env')));
    $config = ['driver' => 'pgsql', 'url' => null, 'host' => '127.0.0.1', 'port' => '5432',
        'database' => 'postgres', 'username' => $environment['DB_USERNAME'] ?? 'postgres',
        'password' => $environment['DB_PASSWORD'] ?? '', 'charset' => 'utf8', 'prefix' => '',
        'search_path' => 'public', 'sslmode' => 'prefer', 'timezone' => 'UTC'];
    unset($environment);
    $assert($config['database'] === 'postgres' && $config['url'] === null && $config['host'] === '127.0.0.1', 'neutral_config_required');
    config(['database.connections.pgsql' => $config]);
    $stage = 'neutral_identity';
    $admin = DB::connection('pgsql');
    $admin->beginTransaction();
    $admin->statement('SET TRANSACTION READ ONLY');
    $admin->statement("SET LOCAL statement_timeout='10s'");
    $admin->statement("SET LOCAL lock_timeout='3s'");
    $server = $admin->selectOne("SELECT current_database() AS name,current_user AS role,session_user AS session_role,
        inet_server_addr()::text AS address,inet_server_port()::text AS port,current_setting('server_version') AS version,
        current_setting('server_version_num') AS version_num,current_setting('transaction_read_only') AS read_only,
        pg_get_userbyid((SELECT datdba FROM pg_database WHERE datname=current_database())) AS database_owner,
        host(inet_server_addr()) AS host_address,family(inet_server_addr()) AS address_family,
        masklen(inet_server_addr()) AS address_mask_bits,
        inet_server_addr()='127.0.0.1'::inet AS exact_loopback");
    $admin->commit();
    // Capture only non-secret comparisons/types before the original fail-closed checks.
    $record['neutral_comparison'] = [
        'database_match' => $server->name === $config['database'],
        'current_user_match' => $server->role === $config['username'],
        'session_user_match' => $server->session_role === $config['username'] && $server->session_role === $server->role,
        // inet::text includes /32; host() is only display normalization. Keep exact inet,
        // IPv4 and full host-mask equality so no subnet/alternate address is admitted.
        'server_address_match' => $server->host_address === $config['host']
            && $server->address_family === 4 && $server->address_mask_bits === 32 && $server->exact_loopback === true,
        'server_address_raw_text_match' => $server->address === $config['host'],
        'exact_loopback_match' => $server->exact_loopback === true,
        'server_port_match' => $server->port === $config['port'],
        'version_supported' => is_string($server->version_num) && ctype_digit($server->version_num)
            && (int) $server->version_num >= 180000 && (int) $server->version_num < 190000,
        'null_field_detected' => in_array(null, [$server->name, $server->role, $server->session_role, $server->address, $server->port, $server->version, $server->version_num,
            $server->host_address, $server->address_family, $server->address_mask_bits, $server->exact_loopback], true),
        'read_only_transaction' => $server->read_only === 'on',
        'types' => [
            'configured_database' => get_debug_type($config['database']), 'actual_database' => get_debug_type($server->name),
            'configured_username' => get_debug_type($config['username']), 'current_user' => get_debug_type($server->role),
            'session_user' => get_debug_type($server->session_role), 'configured_address' => get_debug_type($config['host']),
            'actual_address' => get_debug_type($server->address), 'configured_port' => get_debug_type($config['port']),
            'canonical_host_address' => get_debug_type($server->host_address), 'address_family' => get_debug_type($server->address_family),
            'address_mask_bits' => get_debug_type($server->address_mask_bits), 'exact_loopback' => get_debug_type($server->exact_loopback),
            'actual_port' => get_debug_type($server->port), 'server_version' => get_debug_type($server->version),
            'server_version_num' => get_debug_type($server->version_num),
        ],
        'nonsecret_values' => ['configured_database' => $config['database'], 'actual_database' => $server->name,
            'configured_address' => $config['host'], 'actual_address' => $server->address,
            'canonical_host_address' => $server->host_address, 'address_family' => $server->address_family,
            'address_mask_bits' => $server->address_mask_bits,
            'configured_port' => $config['port'], 'actual_port' => $server->port,
            'server_version' => $server->version, 'server_version_num' => $server->version_num],
    ];
    $assert($server->read_only === 'on', 'read_only_identity_required');
    $assert($server->name === 'postgres' && $server->role === $config['username'] && $server->session_role === $server->role
        && $server->host_address === '127.0.0.1' && $server->address_family === 4 && $server->address_mask_bits === 32
        && $server->exact_loopback === true && $server->port === '5432', 'neutral_identity_mismatch');
    $assert((int) $server->version_num >= 180000 && (int) $server->version_num < 190000, 'postgres18_required');
    if ($proofAccess) {
        $record['disposable_catalog_before'] = $admin->select("SELECT datname AS database, oid::text AS oid,
            pg_get_userbyid(datdba)=current_user AS owner_matches,
            COALESCE(shobj_description(oid,'pg_database') ~ '^silansia-phase1:[0-9a-f]{64}$',false) AS marker_format_matches
            FROM pg_database WHERE datname ~ '^silansia_test_[a-f0-9]{16}$' ORDER BY datname");
    }
    if ($imp03Evidence || $proofAccess) {
        $assert($server->database_owner === $server->role, 'neutral_database_owner_mismatch');
        config(['database.default' => 'silansia_testing']);
        $assert($app->environment('testing') && config('database.default') === 'silansia_testing'
            && ! isset($app['db']->getConnections()['silansia_testing']), 'academic_default_precreate_guard');
        $assert(! $academic || getenv('SILANSIA_ACADEMIC_PROOF_SCAN') === '1', 'academic_child_flag_required');
        $record['precreate'] = ['environment' => 'testing', 'default' => 'silansia_testing', 'neutral_owner_verified' => true];
    }
    if ($preflightOnly) {
        if ($proofClosure) {
            $candidate = 'silansia_test_4c835a1f0c428df9';
            $candidateOid = '243065';
            $admin->beginTransaction();
            try {
                $admin->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $admin->statement("SET LOCAL statement_timeout='5s'");
                $assert($admin->scalar("SELECT current_database()='postgres' AND current_setting('transaction_read_only')='on'") === true,
                    'candidate_metadata_neutral_readonly_required');
                $rows = $admin->select("SELECT datname AS database, oid::text AS oid, pg_get_userbyid(datdba) AS owner,
                    pg_get_userbyid(datdba)=current_user AS owner_matches, datallowconn AS allows_connections,
                    COALESCE(shobj_description(oid,'pg_database') ~ '^silansia-phase1:[0-9a-f]{64}$',false) AS marker_format_matches,
                    (SELECT count(*)::int FROM pg_stat_activity a WHERE a.datid=d.oid) AS backend_count
                    FROM pg_database d WHERE datname=? OR oid=?::oid ORDER BY datname", [$candidate, $candidateOid]);
                $record['candidate_review'] = ['name_and_oid_lookup' => true, 'neutral_readonly_verified' => true,
                    'matches' => $rows, 'candidate_connected' => false, 'deletion_authorized' => false];
                $admin->commit();
            } catch (Throwable $error) {
                $admin->rollBack();
                throw $error;
            }
        }
        $stage = 'neutral_identity_verified';
        $record['status'] = 'PASS';
    } else {
    $assert(is_file(__DIR__.'/proof-scan-runtime-cases.php'), 'functional_cases_required_before_create');
    $privilege = $admin->selectOne('SELECT rolcreatedb,rolsuper FROM pg_roles WHERE rolname=current_user');
    $assert($privilege && ($privilege->rolcreatedb || $privilege->rolsuper), 'createdb_privilege_required');
    $record['createdb_privilege_verified'] = true;
    $assert((int) $admin->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname ~ '^silansia_test_[a-f0-9]{16}$'") === 0, 'existing_test_backend_stop');
    $record['server'] = ['database' => 'postgres', 'owner' => $server->role, 'address' => $server->address, 'port' => $server->port, 'version' => $server->version];
    $database = 'silansia_test_'.$run;
    $token = bin2hex(random_bytes(32));
    $cacheDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-phase1-'.$run;
    $assert(mkdir($cacheDirectory, 0700), 'cache_directory_creation_failed');
    Runtime::path($cacheDirectory, true);
    $cache = $cacheDirectory.DIRECTORY_SEPARATOR.'config.php';
    $assert(preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $database) === 1 && $database !== 'manpro_db', 'random_database_required');
    $assert(! $proofClosure || $database !== 'silansia_test_4c835a1f0c428df9', 'historical_candidate_excluded');
    $assert((int) $admin->scalar('SELECT count(*) FROM pg_database WHERE datname=?', [$database]) === 0, 'database_must_not_exist');
    $record['database_absent_before_create'] = true;
    Runtime::assertProtected();
    $stage = 'create_owned_database';
    $admin->statement('CREATE DATABASE "'.$database.'"');
    $created = true;
    $oid = $admin->scalar('SELECT oid::text FROM pg_database WHERE datname=?', [$database]);
    $admin->statement('COMMENT ON DATABASE "'.$database.'" IS \'silansia-phase1:'.$token.'\'');
    $identity = ['database' => $database, 'oid' => $oid, 'owner' => $server->role,
        'address' => $server->address, 'port' => $server->port, 'host' => $config['host'],
        'connection_port' => $config['port'], 'token' => $token, 'cache' => $cache, 'runtime_root' => Runtime::root()];
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'APP_CONFIG_CACHE' => $cache,
        'SILANSIA_TEST_IDENTITY' => json_encode($identity, JSON_THROW_ON_ERROR)] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    config(['database.default' => 'silansia_testing', 'database.connections.silansia_testing' => [...$config, 'database' => $database],
        'app.key' => $proofAccess ? config('app.key') : 'base64:'.base64_encode(random_bytes(32)), 'hashing.bcrypt.rounds' => 4]);
    $guard = new PostgresTestGuard($identity);
    $db = DB::connection();
    $guard->assertSafe($app, $db);
    $waitForNoPeers = function ($connection, string $target) use ($assert, &$record): void {
        $deadline = microtime(true) + 8;
        do {
            $peers = $connection->select('SELECT pid,backend_type,state,xact_start IS NOT NULL AS transaction_open
                FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid()', [$target]);
            if ($peers === []) {
                return;
            }
            $record['backend_wait_observations'][] = $peers;
            // Never ignore a client/unknown backend and never terminate anything. An internal
            // autovacuum may finish naturally; mutation still requires the final count to be zero.
            $foreign = array_filter($peers, static fn ($peer) => $peer->backend_type !== 'autovacuum worker');
            $assert($foreign === [], 'owned_has_foreign_backend');
            $assert(microtime(true) < $deadline, 'owned_internal_backend_busy');
            usleep(100000);
        } while (true);
    };
    $owned = function () use ($guard, $app, $identity, $assert, $waitForNoPeers): void {
        Runtime::assertProtected();
        $db = DB::connection();
        $guard->assertSafe($app, $db);
        $assert($db->scalar('SELECT session_user::text') === $identity['owner'], 'owned_session_identity_mismatch');
        $waitForNoPeers($db, $identity['database']);
    };
    $owned();
    $assert((int) $db->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND (state IS DISTINCT FROM 'idle' OR xact_start IS NOT NULL)") === 0, 'other_active_backend_stop');
    $record['identity'] = array_diff_key($identity, array_flip(['token', 'cache']));
    $record['identity']['marker_verified'] = true;
    if ($proofAccess) {
        $receipt = ['run' => $run, 'verified_at' => gmdate('c'), 'identity' => $record['identity'],
            'ownership_digest' => hash('sha256', 'silansia-phase1:'.$token)];
        $assert(file_put_contents(dirname($evidence).'/OWNED_'.$run.'.json',
            json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'proof_access_receipt_write_failed');
    }
    $record['laravel'] = Application::VERSION;
    $migrate = function (array $files, string $action = 'migrate') use ($owned, $kernel, &$record): void {
        $owned();
        $output = new BufferedOutput;
        $options = ['--database' => 'silansia_testing', '--force' => true, '--no-interaction' => true,
            '--path' => $files, '--realpath' => true];
        if ($action === 'migrate:rollback') {
            $options['--step'] = 1;
        }
        $exit = $kernel->call($action, $options, $output);
        $record['migrations'][] = ['command' => $action, 'paths' => array_map('basename', $files), 'exit' => $exit, 'output' => $output->fetch()];
        if ($exit !== 0) {
            throw new RuntimeException('migration_command_failed');
        }
        Runtime::assertProtected();
    };
    $all = glob(database_path('migrations/*.php'));
    sort($all);
    $m10 = database_path('migrations/2026_11_18_000010_create_proof_scan_technical_schema.php');
    $m11 = database_path('migrations/2026_11_18_000011_add_proof_scan_completeness_guards.php');
    $stage = 'existing_migrations';
    $migrate(array_values(array_diff($all, [$m10, $m11])));
    app(InvoiceSchemaContract::class)->assertReady();
    $record['financial_before'] = app(InvoiceSchemaContract::class)->fingerprint();
    $legacyProof = null;
    if ($accessCase === 'positive') {
        $owned();
        $fixture = new class {
            use BillingIntegrityFixtures {
                billingGraph as public graph;
                billingLocks as public locks;
                billingSubmit as public submit;
            }
        };
        $graph = $fixture->graph();
        $legacyProof = DB::transaction(function () use ($fixture, $graph) {
            $fixture->locks($graph);

            return $fixture->submit($graph);
        }, 1);
    }
    $stage = 'M10';
    $migrate([$m10]);
    $stage = 'M10_catalog';
    $schema = app(ProofScanSchemaContract::class);
    $schema->prepareExpected();
    $schema->assertReady(false);
    $stage = 'M11';
    $migrate([$m11]);
    $schema->assertReady();
    app(InvoiceSchemaContract::class)->assertReady();
    $record['financial_after'] = app(InvoiceSchemaContract::class)->fingerprint();
    $assert(hash_equals($record['financial_before'], $record['financial_after']), 'financial_schema_changed_after_m11');
    if ($writerValidation) {
        $record['readiness'] = ['M10' => true, 'M11' => true, 'CD03_initially_deferred' => true];
    }
    if (! $writerValidation) {
    $stage = 'empty_reversal';
    putenv('SILANSIA_PROOF_SCAN_EMPTY_DOWN='.$token);
    $migrate([$m11], 'migrate:rollback');
    $migrate([$m10], 'migrate:rollback');
    $assert($db->scalar("SELECT to_regclass('public.proof_scan_subjects')") === null, 'empty_reversal_incomplete');
    $migrate([$m10]);
    $migrate([$m11]);
    putenv('SILANSIA_PROOF_SCAN_EMPTY_DOWN');
    }
    $stage = 'functional_tests';
    require __DIR__.'/proof-scan-runtime-cases.php';
    if ($imp03Evidence || $proofAccess) {
        $assert(($record['technical_catalog']['actual_catalog_fingerprint'] ?? null) === '56317b70ecefe5285d728219164082e90d5d38e17d67e544eeceb7ce4ef7b537'
            && $record['financial_before'] === '17c0f079263ed8fc89faf09acc661c52beee1447c9c75f2c1c1ea12e861048c0', 'imp03_pinned_fingerprints_required');
    }
    if ($academic) {
        $stage = 'academic_worker_case';
        require __DIR__.'/academic-proof-scan-runtime-case.php';
    }
    if ($accessCase === 'positive') {
        $stage = 'proof_access_positive';
        (static function () use ($app, $guard, $kernel, $assert, $owned, $legacyProof, $proofClosure, $envelope, &$record): void {
            require __DIR__.'/proof-scan-access-runtime-case.php';
        })();
    }
    if ($paymentWriter) {
        $stage = 'payment_writer_tests';
        $owned();
        config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only-approval',
            'invoice_issuance.enabled' => true, 'payment_core.enabled' => true]);
        $cases = new PaymentScannerGraphCases($owned, static function (array $entry) use (&$record): void {
            $record['writer_tests'][] = $entry;
        }, directVerifyMustDeny: $proofAccess);
        $completed = $cases->run();
        $record['writer_completed'] = array_values(array_filter($completed, static fn ($id) => str_starts_with($id, 'J1-')));
        $record['financial_regression_completed'] = array_values(array_filter($completed, static fn ($id) => str_starts_with($id, 'FIN-')));
        $assert(count($record['writer_completed']) === 11, 'writer_acceptance_case_count');
        $assert(count($record['financial_regression_completed']) === 1, 'financial_regression_case_count');
    }
    $stage = 'final_catalog';
    $schema->assertReady();
    app(InvoiceSchemaContract::class)->assertReady();
    if ($writerValidation) {
        $record['financial_final'] = app(InvoiceSchemaContract::class)->fingerprint();
        $assert(hash_equals($record['financial_before'], $record['financial_final']), 'financial_schema_changed_after_tests');
    }
    if ($proofAccess) {
        $beforeTechnical = $record['technical_catalog']['actual_catalog_fingerprint'];
        $record['technical_final'] = $captureTechnicalCatalog()['actual_fingerprint'];
        $assert(hash_equals($beforeTechnical, $record['technical_final']), 'technical_schema_changed_after_retest');
        $assert(array_filter($record['access']['cases'] ?? [], static fn (array $case): bool => $case['status'] === 'FAIL') === [],
            'proof_access_case_failure');
    }
    Runtime::assertProtected();
    $record['status'] = 'PASS';
    }
} catch (Throwable $error) {
    $record['status'] = 'FAIL';
    $record['stage'] = $stage;
    $record['exception'] = get_class($error);
    $record['message'] = $error instanceof PDOException ? 'database_operation_failed'
        : ($proofAccess && ! preg_match('/\A[a-z0-9_-]{1,100}\z/', $error->getMessage()) ? 'proof_access_failure' : $error->getMessage());
    if ($proofAccess && $error instanceof PaymentFailure && preg_match('/\A[a-z0-9_]{1,100}\z/', $error->kind)) {
        $record['message'] = $error->kind;
    }
    $record['sqlstate'] = $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null;
    if ($writerValidation && $error instanceof PDOException) {
        preg_match('/constraint "([a-z0-9_]+)"/i', $error->errorInfo[2] ?? '', $diagnostic);
        $record['constraint'] = $diagnostic[1] ?? null;
    } else {
        $record['database_diagnostic'] = $error instanceof PDOException ? ($error->errorInfo[2] ?? null) : null;
    }
    $record['trace'] = array_map(static fn ($f) => ['file' => isset($f['file']) ? str_replace(base_path(), '[workspace]', $f['file']) : null, 'line' => $f['line'] ?? null], array_slice($error->getTrace(), 0, 9));
} finally {
    if (isset($admin)) {
        while ($admin->transactionLevel() > 0) {
            $admin->rollBack();
        }
    }
    if ($created && $guard !== null) {
        try {
            // UNKNOWN may replace Laravel's cached connection. Never reconnect just to close it.
            foreach ($app['db']->getConnections() as $connection) {
                if ($connection === $admin) {
                    continue;
                }
                $assert($connection->getName() === 'silansia_testing'
                    && $connection->getConfig('database') === $identity['database'], 'unowned_cached_connection');
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                $guard->assertTarget($connection);
                $connection->disconnect();
            }
            if (isset($db)) {
                $db->disconnect();
            }
            Runtime::assertProtected();
            $guard->assertOwnedForCleanup($admin);
            if ($proofClosure) {
                $receiptPath = dirname($evidence).'/OWNED_'.$run.'.json';
                $readback = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
                $assert($readback === $receipt && hash_equals($readback['ownership_digest'], hash('sha256', 'silansia-phase1:'.$identity['token'])),
                    'proof_closure_exact_receipt_mismatch');
                $record['cleanup_exact_receipt_verified'] = true;
            }
            $assert($admin->scalar('SELECT current_database()') === 'postgres', 'neutral_cleanup_database_required');
            $waitForNoPeers($admin, $identity['database']);
            $peers = $admin->select('SELECT pid,backend_type,state,xact_start IS NOT NULL AS transaction_open FROM pg_stat_activity WHERE datname=?', [$identity['database']]);
            $record['cleanup_backends'] = $peers;
            $assert($peers === [], 'cleanup_has_other_backends');
            $admin->statement('DROP DATABASE "'.$identity['database'].'"');
            if ($proofAccess) {
                $assert((int) $admin->scalar('SELECT count(*) FROM pg_database WHERE datname=? OR oid=?::oid',
                    [$identity['database'], $identity['oid']]) === 0, 'proof_access_database_remains_after_drop');
                $record['cleanup_name_and_oid_absent'] = true;
            }
            $record['cleanup'] = 'OWNED DATABASE DROPPED';
        } catch (Throwable $cleanupError) {
            $record['cleanup'] = 'BLOCKED: '.get_class($cleanupError);
            $record['cleanup_reason'] = $cleanupError instanceof PDOException
                ? 'cleanup_database_operation_failed' : $cleanupError->getMessage();
            $record['status'] = 'BLOCKED';
        }
    }
    $record['finished_at'] = gmdate('c');
    file_put_contents($evidence, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['status' => $record['status'] ?? 'BLOCKED', 'stage' => $stage, 'evidence' => basename($evidence), 'cleanup' => $record['cleanup']]).PHP_EOL;
}
exit(($record['status'] ?? null) === 'PASS' ? 0 : 1);
