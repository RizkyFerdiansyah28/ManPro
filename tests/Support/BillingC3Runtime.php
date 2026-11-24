<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Billing\InvoiceSchemaContract;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\ProofScanSchemaContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Tests\Support\PaymentR2IntegrationObserver as Observer;

final class BillingC3Runtime
{
    public const STAGES = ['billing_c3_assets', 'billing_c3_migrate', 'billing_c3_fixture', 'billing_c3_web', 'billing_c3_browser', 'billing_c3_consumers'];

    private const PHASES = ['migrate', 'fixture', 'browser', 'complete'];

    private const TARGET_ENV = ['SILANSIA_TEST_IDENTITY', 'SILANSIA_TEST_DB_DATABASE', 'SILANSIA_TEST_DB_HOST', 'SILANSIA_TEST_DB_PORT',
        'SILANSIA_TEST_DB_USERNAME', 'SILANSIA_TEST_DB_PASSWORD', 'SILANSIA_TEST_DB_SSLMODE'];

    private const BASE_ENV = ['APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'CACHE_STORE', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'FILESYSTEM_DISK',
        'FINANCIAL_OWNERSHIP_LEDGER_ENABLED', 'INVOICE_ISSUANCE_ENABLED', 'PAYMENT_CORE_ENABLED', 'SILANSIA_P94E1_VERIFY',
        'SILANSIA_RUNTIME_ROOT', 'SILANSIA_RUNTIME_OWNER', 'SILANSIA_BILLING_C2_RUN', 'SILANSIA_BILLING_C3_MANIFEST',
        'SILANSIA_BILLING_C3_DEPENDENCY_POLICY',
        'TMP', 'TEMP', 'TMPDIR', 'LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'LOG_CHANNEL', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE',
        'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'APP_KEY'];

    private readonly array $manifest;

    public function __construct(private readonly string $digest, private readonly string $root,
        private readonly BillingC3WindowsHelpers $windowsHelpers)
    {
        $this->manifest = BillingC3Admission::verifyManifest($digest);
    }

    public function assertExecutable(string $name, string $path): void
    {
        BillingC3Admission::assertTool($name, $path, $this->manifest);
    }

    public function execute(array $identity, #[\SensitiveParameter] string $password, callable $assertCluster, string &$stage): array
    {
        $stage = 'billing_c3_assets';
        $root = $this->root;
        $base = dirname(__DIR__, 2);
        BillingC3Admission::verifyManifest($this->digest);
        BillingC2Preflight::checkWindowsRootAcl($root, windowsHelpers: $this->windowsHelpers);
        foreach (['public/build', 'web-docroot', 'chrome', 'artifacts', 'node-cache'] as $dir) {
            Identity::require(mkdir(Runtime::path($root.'/'.$dir), 0700), 'billing_c3_private_directory_failed');
        }

        $node = $this->manifest['tools']['node']['path'];
        $chrome = $this->manifest['tools']['chrome']['path'];
        $this->assertExecutable('node', $node);
        $this->assertExecutable('chrome', $chrome);
        BillingC3Bootstrap::verifyNodeLaunch(false);
        $run = self::run();
        $browser = $this->windowsHelpers->environment(array_intersect_key(getenv(), array_flip([
            'APP_ENV', 'DB_CONNECTION', 'SILANSIA_P94E1_VERIFY', 'SILANSIA_RUNTIME_ROOT', 'SILANSIA_RUNTIME_OWNER', 'TMP', 'TEMP', 'TMPDIR',
        ])) + BillingC3ProcessJob::settings() + ['SILANSIA_BILLING_C3_RUN' => $run, 'SILANSIA_BILLING_C3_MANIFEST' => $this->digest,
            'SILANSIA_BILLING_C3_DEPENDENCY_POLICY' => BillingC3Bootstrap::policyDigest(),
            'npm_config_cache' => $root.'/node-cache']);
        [$code, $json] = BillingC3Process::run([$node, $base.'/tests/Browser/payment-r2-integration-assets.mjs', '--billing-c3'],
            $root, $browser, BillingC3ProcessJob::ASSET_SECONDS);
        Identity::require($code === 0 && json_decode($json, true) === ['ok' => true], 'billing_c3_asset_build_failed');
        BillingC3Admission::verifyManifest($this->digest);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        Identity::require(is_resource($socket), 'billing_c3_web_port_unavailable');
        $address = stream_socket_get_name($socket, false);
        try {
            Identity::require(is_string($address) && preg_match('/\A127\.0\.0\.1:([1-9][0-9]{0,4})\z/', $address, $match) === 1
                && ! in_array($match[1], ['8000', '5187', $identity['connection_port']], true), 'billing_c3_web_port_unavailable');
            $webKey = bin2hex(random_bytes(32));
            $controlKey = bin2hex(random_bytes(32));
            $owned = $this->windowsHelpers->environment(array_intersect_key(getenv(), array_flip(self::BASE_ENV))) + BillingC3ProcessJob::settings() + [
                'APP_URL' => 'http://'.$address, 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'silansia_c3_'.$run,
                'SESSION_SECURE_COOKIE' => 'false', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax',
                'SILANSIA_TEST_IDENTITY' => json_encode($identity, JSON_THROW_ON_ERROR), 'SILANSIA_TEST_DB_DATABASE' => $identity['database'],
                'SILANSIA_TEST_DB_HOST' => '127.0.0.1', 'SILANSIA_TEST_DB_PORT' => $identity['connection_port'],
                'SILANSIA_TEST_DB_USERNAME' => $identity['owner'], 'SILANSIA_TEST_DB_PASSWORD' => $password, 'SILANSIA_TEST_DB_SSLMODE' => 'disable',
            ];
            Identity::write($root.'/c3-control.json', ['key' => $controlKey, 'env' => $owned]);

            $stage = 'billing_c3_migrate';
            self::advance('migrate', $identity);
            $assertCluster();
            $result = $this->runControl('migrate', $browser + ['SILANSIA_BILLING_C3_CONTROL' => $controlKey], 150);
            Identity::require(count($result['schema']['migrations'] ?? []) === 14 && ($result['schema']['m11_deferred'] ?? null) === true,
                'billing_c3_migration_unverified');

            $stage = 'billing_c3_fixture';
            self::advance('fixture', $identity);
            $assertCluster();
            $result = $this->runControl('fixture', $browser + ['SILANSIA_BILLING_C3_CONTROL' => $controlKey], 110);
            Identity::require(($result['initial_empty'] ?? null) === true, 'billing_c3_fixture_unverified');
        } finally {
            fclose($socket);
        }

        $stage = 'billing_c3_web';
        self::advance('browser', $identity);
        $assertCluster();
        $browser += array_intersect_key(getenv(), array_flip(['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'LOG_CHANNEL',
            'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE']));
        $server = $this->startServer($address, $owned + ['SILANSIA_BILLING_C3_WEB_KEY' => $webKey]);
        try {
            $pid = $this->awaitServer($server, $match[1]);
            $stage = 'billing_c3_browser';
            BillingC3Bootstrap::verifyNodeLaunch(true);
            [$code, $output] = BillingC3Process::run([$node, $base.'/tests/Browser/payment-r2-integration-browser.mjs', 'billing-c3'],
                $root, $browser + ['APP_URL' => $owned['APP_URL'], 'SILANSIA_BILLING_C3_WEB_KEY' => $webKey, 'SILANSIA_BILLING_C3_WEB_PID' => (string) $pid,
                    'SILANSIA_BILLING_C3_CONTROL' => $controlKey], BillingC3ProcessJob::BROWSER_SECONDS);
            $facts = json_decode($output, true, 8);
            Identity::require($code === 0 && ($facts['ok'] ?? null) === true && ($facts['closed'] ?? null) === true
                && ($facts['checks'] ?? null) === BillingC3Admission::RUNTIME_CHECKS, 'billing_c3_browser_unverified');
            BillingC3ProcessJob::open()->revoke();
            $server->stopServer();
        } catch (\Throwable $error) {
            BillingC3ProcessJob::signalFailure();
            throw $error;
        }
        $stage = 'billing_c3_consumers';
        self::advance('complete', $identity);
        Runtime::assertProtected();
        BillingC3Admission::verifyManifest($this->digest);
        BillingC2Preflight::checkWindowsRootAcl($root, windowsHelpers: $this->windowsHelpers);
        $assertCluster();

        return $facts['checks'];
    }

    public static function run(): string
    {
        $run = getenv('SILANSIA_BILLING_C2_RUN') ?: getenv('SILANSIA_BILLING_C3_RUN') ?: '';
        Identity::databaseFor($run);

        return $run;
    }

    private static function phaseClaim(string $phase, array $identity): array
    {
        $claim = ['phase' => $phase, 'invocation' => self::run(), 'oid' => $identity['oid'], 'database' => $identity['database'],
            'manifest' => getenv('SILANSIA_BILLING_C3_MANIFEST'), 'dependency_policy' => BillingC3Bootstrap::policyDigest()];
        $claim['digest'] = hash_hmac('sha256', json_encode($claim, JSON_THROW_ON_ERROR), getenv('SILANSIA_RUNTIME_OWNER'));

        return $claim;
    }

    private static function phaseFiles(string $phase): void
    {
        $seen = false;
        foreach (self::PHASES as $candidate) {
            $path = Runtime::path(Runtime::root().'/c3-phase-'.$candidate.'.json');
            Identity::require(! is_link($path) && is_file($path) === ! $seen, 'billing_c3_phase_mismatch');
            $seen = $seen || $candidate === $phase;
        }
        Identity::require($seen, 'billing_c3_phase_mismatch');
    }

    private static function advance(string $phase, array $identity): void
    {
        Identity::write(Runtime::root().'/c3-phase-'.$phase.'.json', self::phaseClaim($phase, $identity));
        self::assertPhase($phase, $identity);
    }

    private static function assertPhase(string $phase, array $identity): void
    {
        clearstatcache();
        self::phaseFiles($phase);
        $root = Runtime::root();
        foreach (self::PHASES as $candidate) {
            Identity::require(Identity::read($root.'/c3-phase-'.$candidate.'.json') === self::phaseClaim($candidate, $identity), 'billing_c3_phase_mismatch');
            if ($candidate === $phase) {
                break;
            }
        }
    }

    public static function assertConnectionEnvironment(array $env): void
    {
        foreach (array_keys($env) as $key) {
            $key = strtoupper($key);
            Identity::require(! str_starts_with($key, 'PG') && ! str_starts_with($key, 'SILANSIA_05E')
                && $key !== 'DATABASE_URL' && $key !== 'APP_PREVIOUS_KEYS'
                && ($key === 'DB_CONNECTION' || ! str_starts_with($key, 'DB_'))
                && (! str_starts_with($key, 'SILANSIA_TEST_DB_') || in_array($key, self::TARGET_ENV, true)), 'billing_c3_external_environment');
        }
    }

    public static function allowedWeb(string $method, string $uri, string $origin, ?string $requestOrigin): bool
    {
        if (! str_starts_with($uri, '/') || str_starts_with($uri, '//') || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\#]/', $uri)
            || ($requestOrigin !== null && $requestOrigin !== $origin)) {
            return false;
        }
        $path = explode('?', $uri, 2)[0];
        $id = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}';

        return match ($method) {
            'HEAD' => str_starts_with($path, '/build/'),
            'GET' => str_starts_with($path, '/build/') || preg_match("~\\A(?:/|/login|/admin|/keluarga|/perawat|/dashboard|/notifications/summary
                |/admin/billing/invoices(?:/create|/resident-options|/$id)?|/keluarga/tagihan(?:/$id)?|/admin/pembayaran(?:/$id/$id)?
                |/keluarga/billing/invoices(?:/$id(?:/payment-context)?)?|/admin/billing/payments
                |/admin/billing/invoices/$id/payments/$id(?:/review-context)?|/billing/payment-commands/recovery-context
                |/billing/invoices/$id/payments/$id/proof)\\z~x", $path) === 1,
            'POST' => preg_match("~\\A(?:/login|/logout|/admin/billing/invoices(?:/preview|/reconcile)?|/keluarga/billing/invoices/$id/payments
                |/admin/billing/invoices/$id/payments/$id/(?:verify|reject)|/billing/payment-commands/reconcile)\\z~x", $path) === 1,
            default => false,
        };
    }

    public static function sameOriginLocation(string $location, string $origin): bool
    {
        $path = str_starts_with($location, $origin.'/') ? substr($location, strlen($origin)) : $location;

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $path);
    }

    public static function validate(string $phase): PostgresTestGuard
    {
        BillingC3ProcessJob::assertLive();
        BillingC3Bootstrap::policyDigest();
        Runtime::environment();
        self::assertConnectionEnvironment(getenv());
        $root = Runtime::root();
        $run = self::run();
        $guard = PostgresTestGuard::fromEnvironment();
        $id = $guard->identity;
        Identity::require($id['cache'] === BillingC2Preflight::assertCachePreconditions() && $id['runtime_root'] === $root
            && $id['database'] === Identity::databaseFor($run) && $id['owner'] === 'silansia_c2_'.$run && $id['port'] === $id['connection_port']
            && $id['host'] === '127.0.0.1' && getenv('APP_ENV') === 'testing' && getenv('DB_CONNECTION') === 'silansia_testing'
            && getenv('SILANSIA_TEST_DB_DATABASE') === $id['database'] && getenv('SILANSIA_TEST_DB_USERNAME') === $id['owner']
            && getenv('SILANSIA_TEST_DB_HOST') === $id['host'] && getenv('SILANSIA_TEST_DB_PORT') === $id['connection_port']
            && preg_match('/\A[a-f0-9]{64}\z/', getenv('SILANSIA_TEST_DB_PASSWORD') ?: '') === 1 && getenv('SILANSIA_TEST_DB_SSLMODE') === 'disable',
            'billing_c3_owned_target_required');
        Identity::verifyReceipt(Identity::read($root.'/database-receipt.json'), $run, $id, getenv('SILANSIA_RUNTIME_OWNER'));
        self::assertPhase($phase, $id);
        Runtime::assertProtected();

        return $guard;
    }

    public static function boot(string $phase): Application
    {
        $guard = self::validate($phase);
        $base = dirname(__DIR__, 2);
        $root = Runtime::root();
        $app = require $base.'/bootstrap/app.php';
        $environment = Runtime::path($root.'/environment', true);
        Identity::require(scandir($environment) === ['.', '..'], 'billing_c3_environment_file_present');
        $app->useEnvironmentPath($environment);
        $app->loadEnvironmentFrom('billing-c3-absent.env');
        $app->usePublicPath(Runtime::path($root.'/public', true));
        Runtime::attach($app);
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($guard): void {
            $config = $app['config'];
            $db = $config->get('database.connections.silansia_testing');
            $id = $guard->identity;
            Identity::require($app->environment('testing') && ! $app->configurationIsCached()
                && $config->get('database.default') === 'silansia_testing' && $config->get('app.key') === getenv('APP_KEY')
                && $config->get('app.previous_keys') === [] && $config->get('session.driver') === 'file'
                && $config->get('payment_http.enabled') === false && $config->get('payment_http.synthetic_validation') === false
                && $config->get('payment_core.enabled') === false && $config->get('invoice_issuance.enabled') === false
                && $config->get('financial_ownership.enabled') === false && is_array($db) && $db['driver'] === 'pgsql' && $db['url'] === null
                && $db['database'] === $id['database'] && $db['host'] === $id['host'] && (string) $db['port'] === $id['connection_port']
                && $db['username'] === $id['owner'] && $db['password'] === getenv('SILANSIA_TEST_DB_PASSWORD') && $db['sslmode'] === 'disable',
                'billing_c3_resolved_config_mismatch');
            $config->set('database.connections', ['silansia_testing' => $db + ['options' => [\PDO::ATTR_PERSISTENT => false]]]);
            // Even the allowed connector may only be opened once bootstrap has finished.
            $app['db']->extend('silansia_testing', static fn () => throw new \LogicException('billing_c3_database_before_bootstrap'));
        });
        $app->make(Kernel::class)->bootstrap();
        Identity::require($app['db']->getConnections() === [], 'billing_c3_database_before_bootstrap');
        $app['db']->forgetExtension('silansia_testing');
        PaymentR2IntegrationRuntime::checkDatabase($app, DB::connection(), $guard);
        self::assertCluster($guard);
        $hot = Runtime::path($root.'/public/c3-hot-absent');
        Identity::require(! file_exists($hot) && ! is_link($hot), 'billing_c3_hot_file_present');
        $app->make(Vite::class)->useHotFile($hot);
        if ($phase !== 'migrate') {
            $app->make(InvoiceSchemaContract::class)->assertReady();
            $technical = $app->make(ProofScanSchemaContract::class);
            $technical->prepareExpected();
            $technical->assertReady(true);
            config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-c3-simulation',
                'invoice_issuance.enabled' => true, 'payment_core.enabled' => true,
                'payment_http.enabled' => $phase === 'browser', 'payment_http.synthetic_validation' => $phase === 'browser']);
        }
        if ($phase === 'browser') {
            Runtime::path($app->make(PaymentProofEnvironment::class)->root());
        }

        return $app;
    }

    public static function assertCluster(PostgresTestGuard $guard): void
    {
        $proof = Identity::read(Runtime::root().'/cluster-receipt.json');
        $digest = $proof['digest'] ?? '';
        unset($proof['digest']);
        $id = $guard->identity;
        Identity::require($proof['invocation'] === self::run() && $proof['root'] === $id['runtime_root'] && $proof['owner'] === $id['owner']
            && $proof['host'] === $id['host'] && $proof['port'] === $id['connection_port']
            && hash_equals(hash_hmac('sha256', json_encode($proof, JSON_THROW_ON_ERROR), getenv('SILANSIA_RUNTIME_OWNER')), $digest),
            'billing_c3_cluster_receipt_mismatch');
        $facts = DB::selectOne("SELECT system_identifier::text AS system_id,current_setting('data_directory') AS data,
            current_setting('listen_addresses') AS listen,current_setting('unix_socket_directories') AS sockets,
            current_setting('port') AS port,inet_server_port()::text AS server_port,host(inet_server_addr()) AS host,
            floor(extract(epoch FROM pg_postmaster_start_time()))::bigint::text AS started,
            current_user::text AS role,session_user::text AS session_role,current_setting('server_version') AS version FROM pg_control_system()");
        // Compare the server with the signed cluster receipt, plus the PDO-target checks above.
        Identity::require($facts->system_id === $proof['system_id'] && str_replace('/', '\\', $facts->data) === $proof['data']
            && $facts->listen === '127.0.0.1' && $facts->sockets === '' && $facts->host === '127.0.0.1'
            && $facts->port === $proof['port'] && $facts->server_port === $proof['port'] && $facts->started === $proof['started']
            && $facts->role === $id['owner'] && $facts->session_role === $id['owner'] && preg_match('/\A18\.3(?:\z|\s)/', $facts->version) === 1,
            'billing_c3_cluster_server_mismatch');
    }

    public static function control(string $action, array $args, BillingC3WindowsHelpers $windowsHelpers): void
    {
        $policyDigest = BillingC3Bootstrap::policyDigest();
        $root = Runtime::root();
        $control = Identity::read($root.'/c3-control.json');
        $key = getenv('SILANSIA_BILLING_C3_CONTROL') ?: '';
        Identity::require(preg_match('/\A[a-f0-9]{64}\z/', $key) === 1 && hash_equals($control['key'], $key)
            && $control['env']['SILANSIA_RUNTIME_ROOT'] === $root && $control['env']['SILANSIA_RUNTIME_OWNER'] === getenv('SILANSIA_RUNTIME_OWNER')
            && $control['env']['SILANSIA_BILLING_C3_MANIFEST'] === getenv('SILANSIA_BILLING_C3_MANIFEST')
            && $control['env']['SILANSIA_BILLING_C3_DEPENDENCY_POLICY'] === $policyDigest, 'billing_c3_control_claim_invalid');
        self::assertConnectionEnvironment(getenv());
        $launcherEnvironment = $windowsHelpers->environment(getenv());
        foreach (self::TARGET_ENV as $name) {
            Identity::require(getenv($name) === false, 'billing_c3_control_inherited_database');
        }
        $windowsHelpers->assertEnvironment($control['env']);
        BillingC3Bootstrap::assertEnvironment($control['env']);
        foreach ($windowsHelpers->environment($control['env']) as $name => $value) {
            // Values are installed before Laravel is loaded. PHP INI is independently configured at process start.
            putenv($name.'='.$value);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $phase = in_array($action, ['migrate', 'fixture'], true) ? $action : 'browser';
        $guard = self::validate($phase);
        BillingC2Preflight::checkWindowsRootAcl($root, windowsHelpers: $windowsHelpers);
        if ($phase !== 'browser') {
            Identity::writeNew(Runtime::path($root.'/c3-'.$action.'.claim'), 'claimed');
            BillingC3Admission::verifyManifest(getenv('SILANSIA_BILLING_C3_MANIFEST'));
        }
        $app = self::boot($phase);
        try {
            BillingC3ProcessJob::assertLive();
            $result = match ($action) {
                'migrate' => ['schema' => PaymentR2IntegrationRuntime::migrate($app, $guard)],
                'fixture' => self::verifiedFixture($app),
                'before' => ['initial' => BillingC3Fixtures::before($args[0], $guard)],
                'bind' => ['issued' => BillingC3Fixtures::bind($args[0], $args[1], $guard)],
                'observe' => ['snapshot' => Observer::snapshot($args[0], $guard)],
                'acceptance' => ['acceptance' => BillingC3Acceptance::snapshot($guard)],
                'acceptance-initial' => ['acceptance' => BillingC3Acceptance::snapshot($guard, initial: true)],
                'verdict-freshness' => ['freshness' => BillingC3Acceptance::verdictFreshness($args[0], $args[1], $guard)],
                'scan' => ['scan' => BillingC3Fixtures::scan($args[0], $args[1])],
                'visibility' => ['visibility' => BillingC3Fixtures::visibility($args[0], $args[1], $guard)],
                'stale' => ['stale' => BillingC3Fixtures::stale($args[0], $args[1])],
                'transfer' => ['transferred' => BillingC3Fixtures::transfer($args[0])],
                'cancelled-fixture' => ['cancelled' => BillingC3Fixtures::cancelled($args[0])],
                'expire-context' => ['expired' => BillingC3Fixtures::expireContext($args[0])],
                'terminal-context' => ['negative' => BillingC3Fixtures::terminalContext($args[0], $guard)],
                'race' => ['race' => BillingC3Race::run($args[0], $launcherEnvironment)],
                'race-worker' => ['race_worker' => BillingC3Race::worker($args[0], $args[1])],
            };
        } finally {
            DB::disconnect('silansia_testing');
            Runtime::assertProtected();
        }
        echo json_encode(['ok' => true, ...$result], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    private static function verifiedFixture(Application $app): array
    {
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = Request::create(getenv('APP_URL').'/keluarga/billing/invoices', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $kernel->handle($request);
        $body = json_decode($response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
        Identity::require($response->getStatusCode() === 503 && ($body['error']['code'] ?? null) === 'proof_boundary_unavailable',
            'billing_c3_gate_off_negative_failed');

        return BillingC3Fixtures::prepare();
    }

    private function runControl(string $action, array $environment, int $timeout): array
    {
        // CLI children already have the full process isolation profile, except connection credentials.
        $environment += array_intersect_key(getenv(), array_flip(self::BASE_ENV));
        $command = BillingC3Bootstrap::phpCommand([__DIR__.'/run-payment-r2-integration.php', '--billing-c3-control', $action], $this->root);
        [$code, $output] = BillingC3Process::run($command,
            $this->root, $environment, $timeout);
        $result = json_decode($output, true, 16);
        Identity::require($code === 0 && ($result['ok'] ?? null) === true, 'billing_c3_'.$action.'_failed');

        return $result;
    }

    private function startServer(string $address, array $environment): BillingC3Process
    {
        $command = BillingC3Bootstrap::phpCommand(['-d', 'upload_max_filesize=2112K', '-S', $address, '-t', Runtime::path($this->root.'/web-docroot', true),
            __DIR__.'/payment-r2-integration-server.php'], $this->root);

        return BillingC3Process::start($command, $this->root, $environment,
            BillingC3ProcessJob::BROWSER_SECONDS + 2 * BillingC3ProcessJob::HASH_SECONDS + BillingC3ProcessJob::WEB_READY_SECONDS, 0);
    }

    private function awaitServer(BillingC3Process $server, string $port): int
    {
        $settings = $this->windowsHelpers->environment(['TEMP' => $this->root.'/tmp', 'TMP' => $this->root.'/tmp']);
        $deadline = BillingC3ProcessJob::deadline(BillingC3ProcessJob::WEB_READY_SECONDS);
        do {
            $pid = $server->pid();
            [$code, $output] = BillingC3Process::run([$this->windowsHelpers->executable('netstat'), '-ano'], $this->root,
                $settings, 5, 1_048_576);
            Identity::require($code === 0, 'billing_c3_listener_probe_failed');
            if (preg_match('/^\h*TCP\h+127\.0\.0\.1:'.$port.'\h+\S+\h+LISTENING\h+'.$pid.'\h*\r?$/mi', $output) === 1) {
                BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_listener_unverified');
                BillingC2Cluster::assertListenerFacts($output, $port, (string) $server->pid());

                return $pid;
            }
            usleep(100_000);
        } while (hrtime(true) < $deadline);

        throw new \LogicException('billing_c3_listener_unverified');
    }
}
