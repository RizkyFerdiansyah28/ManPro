<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use LogicException;
use PDO;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Throwable;
use WeakReference;

/** Owns one new cluster for one C2 child. Any unconfirmed operation leaves its root for review. */
final class BillingC2Cluster
{
    public const STAGES = ['framework_no_database', 'cache_preconnect', 'owned_cluster', 'initialize_cluster', 'start_cluster', 'verify_cluster',
        'create_database', 'verify_database', 'drop_database', 'release_connections', 'stop_cluster'];

    private const HOST = '127.0.0.1';

    private readonly string $data;

    private readonly string $run;

    private readonly string $owner;

    private readonly string $database;

    private ?BillingC3ClusterProcesses $c3Processes = null;

    public function __construct(private readonly string $bin, private readonly string $root,
        private readonly ?BillingC3WindowsHelpers $windowsHelpers = null)
    {
        Identity::require($root === Runtime::root() && is_dir(self::windowsPath($bin)), 'billing_c2_cluster_context_invalid');
        $this->data = self::windowsPath($root.'\\postgres-data');
        $this->run = getenv('SILANSIA_BILLING_C2_RUN') ?: '';
        $this->database = Identity::databaseFor($this->run);
        $this->owner = 'silansia_c2_'.$this->run;
    }

    public static function windowsPath(string $path): string
    {
        // pg_ctl delegates server launch to platform process helpers. Reject shell metacharacters and path aliases.
        $path = str_replace('/', '\\', $path);
        Identity::require(preg_match('~\A[A-Za-z]:\\\\[A-Za-z0-9_. -]+(?:\\\\[A-Za-z0-9_. -]+)*\z~', $path) === 1
            && ! preg_match('~[ .](?:\\\\|\z)~', $path), 'billing_c2_windows_path_invalid');
        Runtime::canonical($path);

        return $path;
    }

    public function verify(string &$stage, ?BillingC3Runtime $c3 = null, ?Application $demoApp = null): array
    {
        Identity::require(($c3 === null) === ($this->windowsHelpers === null), 'billing_c3_windows_helper_profile_required');
        if ($demoApp !== null) {
            Identity::require($c3 === null, 'billing_demo_invocation_required');
            BillingMinimumDemo::assertInvocation($this->root, $this->run);
        }
        if ($c3 !== null) {
            foreach (['postgres-bin', 'postgres-lib', 'postgres-share'] as $tree) {
                BillingC3Bootstrap::verifyHostRoot($tree);
            }
        }
        $stage = 'cache_preconnect';
        BillingC2Preflight::assertCachePreconditions();

        $stage = 'owned_cluster';
        $versions = [];
        foreach (['initdb', 'pg_ctl', 'pg_controldata', 'postgres'] as $tool) {
            $c3?->assertExecutable($tool, $this->tool($tool));
            Identity::require(preg_match('/\(PostgreSQL\) (18(?:\.[0-9]+)+)\s*\z/', $this->command($tool, ['--version'], 10), $match) === 1,
                'billing_c2_postgres_18_required');
            $versions[] = $match[1];
        }
        Identity::require(count(array_unique($versions)) === 1, 'billing_c2_postgres_tool_versions_mismatch');
        $stage = 'initialize_cluster';
        if ($c3 !== null) {
            BillingC3ProcessJob::assertLive();
        }
        Identity::require(! file_exists($this->data) && ! is_link($this->data) && mkdir(Runtime::path($this->data), 0700),
            'billing_c2_data_directory_exists');
        $password = bin2hex(random_bytes(32));
        $passwordFile = Runtime::path($this->root.'/initdb-password');
        Identity::writeNew($passwordFile, $password.PHP_EOL);
        [$code] = $this->run([$this->tool('initdb'), '-D', $this->data, '-U', $this->owner, '--encoding=UTF8', '--locale=C',
            '--auth=scram-sha-256', '--pwfile', $passwordFile], 100, 65536);
        // A returned exit code proves initdb exited. Keep artifacts on timeout or ambiguous ownership.
        clearstatcache(true);
        $passwordFile = Runtime::path($passwordFile);
        Identity::require(Runtime::root() === $this->root && is_file($passwordFile)
            && hash_file('sha256', $passwordFile) === hash('sha256', $password.PHP_EOL)
            && unlink($passwordFile), 'billing_c2_init_secret_cleanup_failed');
        Identity::require($code === 0, 'billing_c2_process_failed');
        $control = $this->control();
        Identity::require($control['state'] === 'shut down', 'billing_c2_initialized_cluster_state_invalid');

        $stage = 'start_cluster';
        $reservation = @stream_socket_server('tcp://'.self::HOST.':0');
        Identity::require(is_resource($reservation), 'billing_c2_port_reservation_failed');
        try {
            $endpoint = stream_socket_get_name($reservation, false);
            Identity::require(is_string($endpoint) && preg_match('/\A127\.0\.0\.1:([0-9]{4,5})\z/', $endpoint, $match) === 1,
                'billing_c2_loopback_endpoint_invalid');
            $port = $match[1];
            Identity::require((int) $port > 1024 && (int) $port <= 65535 && ! in_array($port, ['5432', '5187', '8000'], true),
                'billing_c2_port_disallowed');
            $this->assertOwnedListener($port, (string) getmypid());
            $file = Runtime::path($this->data.'\\postgresql.conf');
            $settings = "\nlisten_addresses = '127.0.0.1'\nport = {$port}\nunix_socket_directories = ''\nssl = off\n";
            Identity::require(is_file($file) && file_put_contents($file, $settings, FILE_APPEND | LOCK_EX) === strlen($settings),
                'billing_c2_cluster_configuration_failed');
            $log = Runtime::path($this->root.'/storage/logs/postgres.log');
            Identity::require(! file_exists($this->data.'\\postmaster.pid') && ! is_link($this->data.'\\postmaster.pid'), 'billing_c2_unexpected_cluster_pidfile');
            $started = time();
        } finally {
            fclose($reservation);
        }
        $startup = ['start', '-D', $this->data, '-p', $this->bin.'\\postgres.exe', '-l', $log, '-w', '-t', '25'];
        $launch = null;
        if ($c3 === null) {
            $this->command('pg_ctl', $startup, 35);
        } else {
            $pgCtl = $this->tool('pg_ctl');
            $postgres = $this->tool('postgres');
            $c3->assertExecutable('pg_ctl', $pgCtl);
            $c3->assertExecutable('postgres', $postgres);
            $cmd = $this->windowsHelpers->executable('cmd');
            $environment = $this->processEnvironment();
            $deadline = BillingC3ProcessJob::deadline(35);
            $launch = BillingC3ProcessJob::open()->launchClusterCommand([$pgCtl, ...$startup], $this->root, $environment);
            $launch->awaitSuccess($deadline);
        }

        $stage = 'verify_cluster';
        $pid = $this->pidFile($port);
        Identity::require((int) $pid['started'] >= $started && (int) $pid['started'] <= time(), 'billing_c2_pidfile_start_mismatch');
        $this->assertOwnedListener($port, $pid['pid']);
        $expected = ['system_id' => $control['system_id'], 'data' => $this->data, 'port' => $port, ...$pid];
        $proof = $this->clusterProof($expected);
        if ($launch !== null) {
            $this->c3Processes = BillingC3ClusterProcesses::bind($launch, $proof, $cmd, $postgres);
        }
        Identity::write($this->root.'/cluster-receipt.json', $proof);
        $c3Checks = [];
        $demoResult = null;
        $oid = self::withDisconnectedConnection(
            new PostgresConnection(Identity::neutral(self::HOST, $port, $this->owner, $password), 'postgres'),
            function (PostgresConnection $admin) use ($expected, $password, &$stage, $c3, &$c3Checks, $demoApp, &$demoResult): string {
                return $this->verifyDisposableDatabase($admin, $expected, $password, $stage, $c3, $c3Checks, $demoApp, $demoResult);
            },
            function () use ($expected, $proof, &$stage): void {
                $stage = 'stop_cluster';
                if ($this->c3Processes !== null) {
                    $this->c3Processes->assertLive($proof);
                    Identity::require($this->pidFile($expected['port']) === ['pid' => $expected['pid'], 'started' => $expected['started']],
                        'billing_c2_cluster_proof_mismatch');
                    $this->assertC3ClusterCleanup();
                }
                $this->command('pg_ctl', ['stop', '-D', $this->data, '-m', 'smart', '-w', '-t', '25'], 35);
                clearstatcache(true);
                Identity::require(! file_exists($this->data.'\\postmaster.pid') && ! is_link($this->data.'\\postmaster.pid')
                    && $this->control() === ['system_id' => $expected['system_id'], 'state' => 'shut down'], 'billing_c2_shutdown_unconfirmed');
                if ($this->c3Processes !== null) {
                    $this->c3Processes->assertCleanShutdown($this->c3Coordinators(BillingC3ProcessJob::open()),
                        BillingC3ProcessJob::deadline(BillingC3ProcessJob::EXIT_SECONDS));
                }
            },
        );

        return ['database_dropped' => true, 'cluster_stopped' => true, 'loopback_port' => $port, 'database_oid' => $oid,
            'negative_catalog_checks' => 3, ...($c3 === null ? [] : ['c3_checks' => $c3Checks]),
            ...($demoApp === null ? [] : ['demo' => $demoResult])];
    }

    private function verifyDisposableDatabase(PostgresConnection $admin, array $expected, #[\SensitiveParameter] string $password, string &$stage,
        ?BillingC3Runtime $c3, array &$c3Checks, ?Application $demoApp, ?array &$demoResult): string
    {
        $neutral = $admin->getPdo();
        $port = $expected['port'];
        $this->assertOwnedCluster($neutral, $expected);
        $this->assertDatabaseList($neutral, false);
        Identity::require(Identity::catalog($neutral, $this->database) === false, 'billing_c2_target_already_exists');

        $stage = 'create_database';
        BillingC2Baseline::verify(dirname(__DIR__, 2));
        $this->assertOwnedCluster($neutral, $expected);
        $cache = BillingC2Preflight::assertCachePreconditions();
        if ($c3 !== null) {
            BillingC3ProcessJob::assertLive();
        }
        $neutral->exec('CREATE DATABASE "'.$this->database.'"');
        $row = Identity::catalog($neutral, $this->database);
        Identity::require(is_array($row) && ctype_digit($row['oid']) && $row['owner'] === $this->owner && $row['marker'] === null,
            'billing_c2_created_database_identity_invalid');
        $identity = ['database' => $this->database, 'oid' => $row['oid'], 'owner' => $this->owner,
            'address' => (string) $neutral->query('SELECT inet_server_addr()::text')->fetchColumn(),
            'port' => $port, 'host' => self::HOST, 'connection_port' => $port, 'token' => bin2hex(random_bytes(32)),
            'cache' => $cache, 'runtime_root' => $this->root];
        $guard = new PostgresTestGuard($identity);
        $guard->assertCacheIsolated();
        $this->assertOwnedCluster($neutral, $expected);
        $neutral->exec('COMMENT ON DATABASE "'.$this->database.'" IS '.$neutral->quote('silansia-phase1:'.$identity['token']));
        Identity::verifyCatalog($neutral, $identity);
        Identity::write($this->root.'/database-receipt.json', Identity::receipt($this->run, $identity, getenv('SILANSIA_RUNTIME_OWNER')));

        $stage = 'verify_database';
        $this->assertDatabaseList($neutral, true);
        $this->verifyTarget($identity, $guard, $expected, $password);
        $this->verifyNegativeOwnership($neutral, $identity);
        if ($c3 !== null) {
            $c3Checks = $c3->execute($identity, $password, function () use ($neutral, $expected, $identity): void {
                $this->assertOwnedCluster($neutral, $expected);
                Identity::verifyCatalog($neutral, $identity);
            }, $stage);
        }
        if ($demoApp !== null) {
            $demoResult = BillingMinimumDemo::run($demoApp, $guard, $password, function () use ($neutral, $expected, $identity): void {
                $this->assertOwnedCluster($neutral, $expected);
                Identity::verifyCatalog($neutral, $identity);
            }, $stage);
        }

        $stage = 'drop_database';
        Runtime::assertProtected();
        BillingC2Baseline::verify(dirname(__DIR__, 2));
        $this->assertOwnedCluster($neutral, $expected);
        Identity::verifyReceipt(Identity::read($this->root.'/database-receipt.json'), $this->run, $identity, getenv('SILANSIA_RUNTIME_OWNER'));
        $guard->assertOwnedForCleanup($admin);
        $this->assertNoPeerClients($neutral);
        Identity::verifyCatalog($neutral, $identity);
        if ($c3 !== null) {
            $this->assertC3ClusterCleanup();
        }
        $neutral->exec('DROP DATABASE "'.$this->database.'"');
        $postcheck = $neutral->prepare('SELECT count(*) FROM pg_catalog.pg_database WHERE datname=? OR oid=?::oid');
        $postcheck->execute([$identity['database'], $identity['oid']]);
        Identity::require((int) $postcheck->fetchColumn() === 0, 'billing_c2_database_postcheck_failed');

        $this->assertDatabaseList($neutral, false);
        $this->assertOwnedCluster($neutral, $expected);
        $this->assertNoPeerClients($neutral);
        $stage = 'release_connections';

        return $identity['oid'];
    }

    /** Keep PDO and statement references inside the work scope, then prove release before any continuation. */
    public static function withDisconnectedConnection(PostgresConnection $connection, callable $work, ?callable $afterDisconnect = null): mixed
    {
        Identity::require($connection->getRawPdo() instanceof PDO && $connection->getRawReadPdo() === null
            && $connection->getRawDirectPdo() === null, 'billing_c2_connection_scope_invalid');
        $pdoReference = WeakReference::create($connection->getRawPdo());
        try {
            $result = $work($connection);
        } finally {
            try {
                $connection->disconnect();
            } catch (Throwable $error) {
                throw new LogicException('billing_c2_disconnect_failed', 0, $error);
            }
            Identity::require($connection->getRawPdo() === null && $connection->getRawReadPdo() === null
                && $connection->getRawDirectPdo() === null && $pdoReference->get() === null, 'billing_c2_connection_release_unverified');
        }

        if ($afterDisconnect !== null) {
            $afterDisconnect();
        }

        return $result;
    }

    private function verifyTarget(array $identity, PostgresTestGuard $guard, array $cluster, #[\SensitiveParameter] string $password): void
    {
        $port = $identity['connection_port'];
        $name = $this->database;
        self::withDisconnectedConnection(
            new PostgresConnection(
                new PDO('pgsql:host='.self::HOST.';hostaddr='.self::HOST.';port='.$port.';dbname='.$name.';sslmode=disable;connect_timeout=5',
                    $this->owner, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]),
                $name, '', ['name' => 'silansia_testing', 'driver' => 'pgsql', 'url' => null,
                    'database' => $name, 'host' => self::HOST, 'port' => $port, 'username' => $this->owner],
            ),
            function (PostgresConnection $db) use ($cluster, $guard): void {
                $pdo = $db->getPdo();
                $pdo->exec('SET default_transaction_read_only = on');
                $pdo->exec("SET statement_timeout = '10s'");
                $this->assertServer($pdo, $cluster);
                $guard->assertTarget($db);
                Identity::require($pdo->query("SELECT to_regclass('public.migrations') IS NULL")->fetchColumn() === true,
                    'billing_c2_database_already_migrated');
            },
        );
    }

    private function verifyNegativeOwnership(PDO $neutral, array $identity): void
    {
        foreach (['oid' => '0', 'owner' => 'not_this_invocation', 'token' => str_repeat('0', 64)] as $field => $value) {
            try {
                Identity::verifyCatalog($neutral, array_replace($identity, [$field => $value]));
            } catch (LogicException $error) {
                if ($error->getMessage() === 'owned_catalog_mismatch') {
                    continue;
                }
                throw $error;
            }
            throw new LogicException('billing_c2_negative_ownership_accepted');
        }
    }

    private function assertOwnedCluster(PDO $neutral, array $expected): void
    {
        Runtime::assertProtected();
        $proof = $this->clusterProof($expected);
        $this->c3Processes?->assertLive($proof);
        Identity::require(Identity::read($this->root.'/cluster-receipt.json') === $proof
            && $this->pidFile($expected['port']) === ['pid' => $expected['pid'], 'started' => $expected['started']]
            && $this->control() === ['system_id' => $expected['system_id'], 'state' => 'in production'], 'billing_c2_cluster_proof_mismatch');
        $this->assertOwnedListener($expected['port'], $expected['pid']);
        Identity::assertNeutral($neutral, self::HOST, $expected['port'], $this->owner);
        $this->assertServer($neutral, $expected);
    }

    private function clusterProof(array $expected): array
    {
        $token = getenv('SILANSIA_RUNTIME_OWNER');
        Identity::require(Runtime::root() === $this->root && is_string($token), 'billing_c2_cluster_root_mismatch');
        $parts = ['invocation' => $this->run, 'root' => $this->root, 'owner' => $this->owner, 'host' => self::HOST, ...$expected];
        $parts['digest'] = hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $token);

        return $parts;
    }

    private function assertServer(PDO $pdo, array $expected): void
    {
        $facts = $pdo->query("SELECT system_identifier::text AS system_id,current_setting('data_directory') AS data,
            current_setting('listen_addresses') AS listen,current_setting('unix_socket_directories') AS sockets,
            current_setting('port') AS port,inet_server_port()::text AS server_port,host(inet_server_addr()) AS host,
            floor(extract(epoch FROM pg_postmaster_start_time()))::bigint::text AS started,
            current_user::text AS role,session_user::text AS session_role FROM pg_control_system()")->fetch(PDO::FETCH_ASSOC);
        Identity::require(is_array($facts), 'billing_c2_cluster_facts_missing');
        self::assertServerFacts($facts, $expected, $this->owner);
        self::windowsPath($facts['data']);
    }

    public static function assertServerFacts(array $facts, array $expected, string $owner): void
    {
        $data = $facts['data'] ?? null;
        $started = $facts['started'] ?? null;
        Identity::require(is_string($data) && strcasecmp(str_replace('/', '\\', $data), $expected['data']) === 0
            && ($facts['system_id'] ?? null) === $expected['system_id'] && ($facts['port'] ?? null) === $expected['port']
            && ($facts['server_port'] ?? null) === $expected['port'] && ($facts['host'] ?? null) === self::HOST
            && ($facts['listen'] ?? null) === self::HOST && ($facts['sockets'] ?? null) === ''
            && ($facts['role'] ?? null) === $owner && ($facts['session_role'] ?? null) === $owner
            && is_string($started) && ctype_digit($started) && abs((int) $started - (int) $expected['started']) <= 2,
            'billing_c2_cluster_identity_mismatch');
    }

    private function assertDatabaseList(PDO $pdo, bool $withTarget): void
    {
        $expected = $withTarget ? ['postgres', $this->database, 'template0', 'template1'] : ['postgres', 'template0', 'template1'];
        $rows = $pdo->query('SELECT datname FROM pg_catalog.pg_database ORDER BY datname')->fetchAll(PDO::FETCH_COLUMN);
        Identity::require($rows === $expected, 'billing_c2_unexpected_database_inventory');
    }

    private function assertNoPeerClients(PDO $pdo): void
    {
        Identity::require((int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_stat_activity
            WHERE pid<>pg_backend_pid() AND (backend_type='client backend' OR datname=".$pdo->quote($this->database).')')->fetchColumn() === 0,
            'billing_c2_cluster_peer_present');
    }

    private function assertOwnedListener(string $port, string $pid): void
    {
        if ($this->windowsHelpers === null) {
            $windows = getenv('SystemRoot');
            Identity::require(is_string($windows), 'billing_c2_system_root_required');
            $netstat = self::windowsPath($windows.'\\System32\\netstat.exe');
        } else {
            $netstat = $this->windowsHelpers->executable('netstat');
        }
        [$code, $output] = $this->run([$netstat, '-ano'], 10, 1_048_576);
        Identity::require($code === 0, 'billing_c2_process_failed');
        self::assertListenerFacts($output, $port, $pid);
    }

    public static function assertListenerFacts(string $output, string $port, string $pid): void
    {
        preg_match_all('/^\h*TCP\h+(\S+)\h+\S+\h+LISTENING\h+([0-9]+)\h*\r?$/mi', $output, $rows, PREG_SET_ORDER);
        $selected = [];
        foreach ($rows as [, $address, $owner]) {
            if (str_ends_with($address, ':'.$port) || $owner === $pid) {
                $selected[] = [$address, $owner];
            }
        }
        Identity::require($selected === [[self::HOST.':'.$port, $pid]], 'billing_c2_listener_identity_mismatch');
    }

    private function pidFile(string $port): array
    {
        clearstatcache(true);
        $path = Runtime::path($this->data.'\\postmaster.pid');
        Identity::require(is_file($path) && filesize($path) <= 4096, 'billing_c2_cluster_pidfile_missing');

        return self::parsePidFile(file($path, FILE_IGNORE_NEW_LINES), $this->data, $port);
    }

    public static function parsePidFile(array $lines, string $data, string $port): array
    {
        Identity::require(count($lines) >= 6 && preg_match('/\A[1-9][0-9]*\z/', $lines[0]) === 1
            && strcasecmp(str_replace('/', '\\', $lines[1]), $data) === 0 && ctype_digit($lines[2])
            && $lines[3] === $port && $lines[4] === '' && $lines[5] === self::HOST, 'billing_c2_cluster_pidfile_mismatch');

        return ['pid' => $lines[0], 'started' => $lines[2]];
    }

    private function control(): array
    {
        $path = Runtime::path($this->data.'\\PG_VERSION');
        Identity::require(is_file($path) && trim(file_get_contents($path)) === '18', 'billing_c2_pg_version_mismatch');

        return self::parseControl($this->command('pg_controldata', ['-D', $this->data], 10));
    }

    public static function parseControl(string $output): array
    {
        Identity::require(preg_match_all('/^Database system identifier:[ \t]+([1-9][0-9]{0,19})\r?$/m', $output, $id) === 1
            && preg_match_all('/^Database cluster state:[ \t]+(shut down|in production)\r?$/m', $output, $state) === 1,
            'billing_c2_control_data_unverified');

        return ['system_id' => $id[1][0], 'state' => $state[1][0]];
    }

    private function command(string $tool, array $args, int $timeout): string
    {
        [$code, $output] = $this->run([$this->tool($tool), ...$args], $timeout, 65536);
        Identity::require($code === 0, 'billing_c2_process_failed');

        return $output;
    }

    private function tool(string $tool): string
    {
        $executable = self::windowsPath($this->bin.'\\'.$tool.'.exe');
        Identity::require(is_file($executable), 'billing_c2_postgres_tool_missing');

        return $executable;
    }

    private function run(array $command, int $timeout, int $maxOutput): array
    {
        $environment = $this->processEnvironment();

        return $this->windowsHelpers === null
            ? BillingC2Preflight::runProcess($command, $this->root, $environment, $timeout, $maxOutput)
            : BillingC3Process::run($command, $this->root, $environment, $timeout, $maxOutput, afterRevocation: true);
    }

    private function processEnvironment(): array
    {
        Runtime::assertProtected();
        $temp = Runtime::path($this->root.'/tmp', true);
        $this->windowsHelpers?->assertCmdAutoRunAbsent();
        $settings = ['LANG' => 'C', 'LC_ALL' => 'C', 'TMP' => $temp, 'TEMP' => $temp, 'TMPDIR' => $temp];

        return $this->windowsHelpers?->environment($settings) ?? BillingC2Preflight::restrictedEnvironment($settings);
    }

    private function c3Coordinators(BillingC3ProcessJob $job): array
    {
        $coordinators = [];
        foreach (['PARENT', 'CHILD'] as $role) {
            $pid = (int) getenv('SILANSIA_BILLING_C3_'.$role.'_PID');
            $process = $job->process($pid);
            Identity::require($process['birth'] === getenv('SILANSIA_BILLING_C3_'.$role.'_BIRTH')
                && strcasecmp($process['image'], PHP_BINARY) === 0, 'billing_c3_job_process_identity_mismatch');
            $coordinators[] = $process;
        }
        Identity::require($coordinators[0]['pid'] !== $coordinators[1]['pid'] && $coordinators[1]['pid'] === getmypid(), 'billing_c3_cleanup_process_unverified');

        return $coordinators;
    }

    private function assertC3ClusterCleanup(): void
    {
        Identity::require($this->c3Processes !== null, 'billing_c3_cleanup_process_unverified');
        $job = BillingC3ProcessJob::open();
        $job->assertCleanupProcesses($this->c3Coordinators($job), $this->c3Processes);
    }
}
