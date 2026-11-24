<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use PDO;
use Throwable;

// No activation, writer hook, SQL mutation or runtime repair. Resolve/prepare before L_F.
final class ProofScanReadiness
{
    private const FINANCIAL_SCHEMA_SHA256 = '17c0f079263ed8fc89faf09acc661c52beee1447c9c75f2c1c1ea12e861048c0';

    private ?array $identity = null;

    private ?array $environment = null;

    private ?array $runtime = null;

    private ?PDO $pdo = null;

    private ?PaymentFailure $failure = null;

    private ?string $mode = null;

    private readonly PaymentFailure $unavailable;

    public function __construct(
        private readonly ProofScanSchemaContract $schema,
        private readonly InvoiceSchemaContract $financialSchema,
        private readonly PaymentReadiness $financial,
    ) {
        $this->unavailable = new PaymentFailure('proof_scan_unavailable');
    }

    /** Save a technical failure; future integration MUST look up valid F before enforcing it. */
    public function prepare(): void
    {
        $this->reset('FRESH');
        try {
            $this->prepareOwnedRuntime();
            $this->financial->entry();
            $this->schema->prepareExpected();
        } catch (Throwable) {
            // Pre-created off-lock; no raw path, credentials or infrastructure error disclosure.
            $this->failure = new PaymentFailure('proof_scan_unavailable');
        }
    }

    /** Future fresh/use branch only. Does not replace financial readiness or authorization. */
    public function assertFreshReady(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->mode !== 'FRESH' || config('payment_core.enabled') !== true
            || config('invoice_issuance.enabled') !== true || config('financial_ownership.enabled') !== true) {
            $this->fail();
        }
        $this->assertTransactionIdentity();
        if (DB::scalar("SELECT current_setting('TimeZone')") !== 'UTC') {
            $this->fail();
        }
        // Existing financial pin is not learned or altered. fingerprint() is catalog-only.
        $this->assertFinancialFingerprint();
        $this->schema->assertReady();
    }

    /** Separate future migration permission required. No feature gate is enabled here. */
    public function prepareMigration(string $mode): void
    {
        if (! in_array($mode, ['M10', 'M11', 'DOWN10', 'DOWN11'], true)) {
            $this->fail();
        }
        $this->reset($mode);
        $this->prepareOwnedRuntime();
        $this->financialSchema->assertReady(); // Existing FS-backed guard pin, OFF-LOCK only.
        $this->schema->prepareExpected();
        if (str_starts_with($mode, 'DOWN') && getenv('SILANSIA_PROOF_SCAN_EMPTY_DOWN') !== $this->identity['token']) {
            $this->fail();
        }
    }

    /** No filesystem, logger resolution or new dependency resolution under SQL/financial locks. */
    public function assertMigrationTarget(string $mode): void
    {
        if ($this->mode !== $mode || ! in_array($mode, ['M10', 'M11', 'DOWN10', 'DOWN11'], true)) {
            $this->fail();
        }
        $this->assertTransactionIdentity();
        $this->assertFinancialFingerprint();
        // The existing owned runner retains an idle identity-probe connection. Reject
        // other active backends/open transactions; bounded DDL locks serialize actual writes.
        if ((int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datid=(SELECT oid FROM pg_database WHERE datname=current_database())
            AND pid<>pg_backend_pid() AND (state IS DISTINCT FROM 'idle' OR xact_start IS NOT NULL)") !== 0) {
            $this->fail();
        }
        if ($mode === 'M10') {
            $this->assertAbsent();
        } else {
            $this->schema->assertReady($mode === 'DOWN11');
        }
    }

    public function assertInstalled(bool $completeness): void
    {
        $this->assertTransactionIdentity();
        $this->assertFinancialFingerprint();
        $this->schema->assertReady($completeness);
    }

    public function assertEmptyForReversal(): void
    {
        if (! in_array($this->mode, ['DOWN10', 'DOWN11'], true)
            || getenv('SILANSIA_PROOF_SCAN_EMPTY_DOWN') !== ($this->identity['token'] ?? null)) {
            $this->fail();
        }
        $this->assertTransactionIdentity();
        // No concurrent work/open transaction is permitted. Idle runner probes do not write.
        // These are bounded DDL locks, not financial work.
        DB::statement('LOCK TABLE public.payments,public.proof_scan_attempts,public.proof_scan_audit_events,
            public.proof_scan_jobs,public.proof_scan_policies,public.proof_scan_policy_heads,
            public.proof_scan_subjects,public.proof_scan_verdicts IN ACCESS EXCLUSIVE MODE');
        foreach (['policies', 'policy_heads', 'subjects', 'jobs', 'attempts', 'verdicts', 'audit_events'] as $suffix) {
            if (DB::table('public.proof_scan_'.$suffix)->exists()) {
                $this->fail();
            }
        }
        if (DB::table('public.payments')->where('billing_schema_version', 1)->exists()) {
            $this->fail();
        }
    }

    /** Off-lock, same prepared contract instance used by this readiness object. */
    public function removalStatements(bool $completenessOnly): array
    {
        if (DB::transactionLevel() !== 0 || $this->mode !== ($completenessOnly ? 'DOWN11' : 'DOWN10')) {
            $this->fail();
        }

        return $this->schema->removalStatements($completenessOnly);
    }

    private function reset(string $mode): void
    {
        $this->identity = null;
        $this->environment = null;
        $this->runtime = null;
        $this->pdo = null;
        $this->failure = null;
        $this->mode = $mode;
    }

    private function prepareOwnedRuntime(): void
    {
        // Inspect configuration before opening any PDO/issuing SQL. Never try an alternate DB.
        if (DB::transactionLevel() !== 0 || ! app()->environment('testing')
            || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing' || app()->configurationIsCached()
            || config('database.default') !== 'silansia_testing' || DB::getDriverName() !== 'pgsql') {
            $this->fail();
        }
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '', true);
        foreach (['database', 'oid', 'owner', 'address', 'port', 'host', 'connection_port', 'token', 'cache', 'runtime_root'] as $key) {
            if (! is_array($identity) || ! isset($identity[$key]) || ! is_string($identity[$key]) || $identity[$key] === '') {
                $this->fail();
            }
        }
        if (! preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $identity['database'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $identity['token']) || ! ctype_digit($identity['oid'])
            || ! in_array($identity['host'], ['127.0.0.1', '::1', 'localhost'], true)
            || ! ctype_digit($identity['port']) || ! ctype_digit($identity['connection_port'])
            || (int) $identity['port'] < 1 || (int) $identity['port'] > 65535
            || (int) $identity['connection_port'] < 1 || (int) $identity['connection_port'] > 65535) {
            $this->fail();
        }
        $this->identity = $identity;
        $this->assertConnectionConfiguration();
        $this->prepareFrameworkPaths();
        $cache = $identity['cache'];
        $this->canonical($cache);
        if (getenv('APP_CONFIG_CACHE') !== $cache || file_exists($cache) || is_link($cache)
            || ! is_dir(dirname($cache))
            || ! str_starts_with($cache, sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-phase1-')) {
            $this->fail();
        }
        $this->environment = $this->environmentSnapshot();
        $this->runtime = $this->runtimeSnapshot();
        $this->pdo = DB::connection()->getPdo();
        $this->assertDatabaseIdentity();
    }

    private function assertConnectionConfiguration(): void
    {
        $db = DB::connection();
        $i = $this->identity;
        if ($i === null || ! app()->environment('testing') || config('database.default') !== 'silansia_testing'
            || $db->getName() !== 'silansia_testing' || $db->getDriverName() !== 'pgsql'
            || $db->getConfig('url') !== null || $db->getConfig('database') !== $i['database']
            || $db->getConfig('host') !== $i['host'] || (string) $db->getConfig('port') !== $i['connection_port']
            || $db->getConfig('username') !== $i['owner'] || config('database.connections.pgsql.database') === $i['database']
            || ! empty($db->getConfig('read')) || ! empty($db->getConfig('write'))
            || ! empty($db->getConfig('sticky'))) {
            $this->fail();
        }
    }

    private function assertDatabaseIdentity(): void
    {
        $i = $this->identity;
        $row = DB::selectOne("SELECT current_database() AS name,current_user AS role,session_user AS session_role,d.oid::text AS oid,
            pg_get_userbyid(d.datdba) AS owner,COALESCE(inet_server_addr()::text,'') AS address,
            COALESCE(inet_server_port()::text,'') AS port,shobj_description(d.oid,'pg_database') AS marker,
            current_setting('server_version_num') AS version
            FROM pg_database d WHERE datname=current_database()");
        if (! $row || $row->name !== $i['database'] || $row->oid !== $i['oid']
            || $row->role !== $i['owner'] || $row->session_role !== $i['owner'] || $row->owner !== $i['owner']
            || $row->address !== $i['address'] || $row->port !== $i['port'] || $row->marker !== 'silansia-phase1:'.$i['token']
            || (int) $row->version < 180000 || (int) $row->version >= 190000) {
            $this->fail();
        }
    }

    private function assertTransactionIdentity(): void
    {
        if ($this->identity === null || $this->environment === null || $this->runtime === null || $this->pdo === null
            || $this->environment !== $this->environmentSnapshot() || $this->runtime !== $this->runtimeSnapshot()
            || DB::transactionLevel() !== 1 || DB::connection()->getPdo() !== $this->pdo) {
            $this->fail();
        }
        $this->assertConnectionConfiguration();
        $this->assertDatabaseIdentity();
        if (DB::scalar("SELECT current_setting('transaction_isolation')") !== 'read committed'
            || DB::scalar("SELECT current_setting('transaction_read_only')") !== 'off'
            || DB::scalar("SELECT current_setting('session_replication_role')") !== 'origin') {
            $this->fail();
        }
    }

    private function prepareFrameworkPaths(): void
    {
        $root = getenv('SILANSIA_RUNTIME_ROOT');
        if (getenv('SILANSIA_P94E1_VERIFY') !== '1' || ! is_string($root) || $root !== $this->identity['runtime_root']
            || ! preg_match('/\Asilansia-p94e1-[a-f0-9]{16}\z/', basename($root))
            || ! is_dir($root) || $this->inside($root, base_path())) {
            $this->fail();
        }
        $this->canonical($root);
        $marker = $root.DIRECTORY_SEPARATOR.'owner.json';
        $this->canonical($marker);
        if (! is_file($marker)) {
            $this->fail();
        }
        $owner = json_decode(file_get_contents($marker), true);
        $token = getenv('SILANSIA_RUNTIME_OWNER');
        if (! is_array($owner) || ($owner['root'] ?? null) !== $root || ! is_string($token)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $token)
            || ! is_string($owner['token'] ?? null) || ! hash_equals($owner['token'], $token)) {
            $this->fail();
        }
        foreach (['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'TMP', 'TEMP', 'TMPDIR'] as $key) {
            $this->ownedPath(getenv($key), true);
        }
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            $this->ownedPath(getenv($key), false);
        }
        if (str_replace('\\', '/', ini_get('auto_prepend_file')) !== str_replace('\\', '/', base_path('tests/Support/isolated-runtime-prepend.php'))
            || getenv('LOG_CHANNEL') !== '"null"') {
            $this->fail();
        }
        foreach ([sys_get_temp_dir(), ini_get('upload_tmp_dir'), ini_get('session.save_path')] as $path) {
            $this->ownedPath($path, true);
        }
        $this->ownedPath(ini_get('error_log'), false);
        $this->ownedPath(app()->storagePath(), true);
        foreach (['view.compiled', 'cache.stores.file.path', 'cache.stores.file.lock_path', 'session.files', 'filesystems.disks.local.root', 'filesystems.disks.public.root'] as $key) {
            $this->ownedPath(config($key), true);
        }
        foreach (['single', 'daily', 'emergency'] as $channel) {
            $this->ownedPath(config('logging.channels.'.$channel.'.path', app()->storagePath('logs/laravel.log')), false);
        }
        foreach (['getCachedConfigPath', 'getCachedServicesPath', 'getCachedPackagesPath', 'getCachedRoutesPath', 'getCachedEventsPath'] as $method) {
            $this->ownedPath(app()->{$method}(), false);
        }
        $channel = config('logging.default');
        if (! in_array($channel, ['null', 'p93d_null'], true) || config('logging.channels.'.$channel.'.driver') !== 'monolog'
            || config('logging.channels.'.$channel.'.handler') !== NullHandler::class) {
            $this->fail();
        }
        // Resolve NOW, outside locks; do not log or write evidence from this app class.
        $handlers = app('log')->channel($channel)->getLogger()->getHandlers();
        if (count($handlers) !== 1 || get_class($handlers[0]) !== NullHandler::class) {
            $this->fail();
        }
    }

    private function environmentSnapshot(): array
    {
        $result = [];
        foreach (['APP_ENV', 'DB_CONNECTION', 'SILANSIA_TEST_IDENTITY', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE',
            'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'SILANSIA_P94E1_VERIFY',
            'SILANSIA_RUNTIME_ROOT', 'SILANSIA_RUNTIME_OWNER', 'LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH',
            'TMP', 'TEMP', 'TMPDIR', 'LOG_CHANNEL'] as $name) {
            $result[$name] = getenv($name);
        }

        return $result;
    }

    private function runtimeSnapshot(): array
    {
        $result = ['storage' => app()->storagePath(), 'config_cache' => app()->getCachedConfigPath(),
            'temp' => sys_get_temp_dir(), 'php_uploads' => ini_get('upload_tmp_dir'),
            'php_sessions' => ini_get('session.save_path'), 'php_errors' => ini_get('error_log'),
            'prepend' => ini_get('auto_prepend_file')];
        foreach (['view.compiled', 'cache.stores.file.path', 'cache.stores.file.lock_path', 'session.files',
            'filesystems.disks.local.root', 'filesystems.disks.public.root', 'logging.default',
            'logging.channels.single.path', 'logging.channels.daily.path', 'logging.channels.emergency.path',
            'logging.channels.null.driver', 'logging.channels.null.handler',
            'logging.channels.p93d_null.driver', 'logging.channels.p93d_null.handler'] as $key) {
            $result[$key] = config($key);
        }

        return $result;
    }

    private function ownedPath(mixed $path, bool $directory): void
    {
        $root = $this->identity['runtime_root'];
        if (! is_string($path) || ! $this->inside($path, $root)) {
            $this->fail();
        }
        $this->canonical($path);
        if (($directory && (! is_dir($path) || ! is_writable($path)))
            || (! $directory && ! is_writable(is_dir($path) ? $path : dirname($path)))) {
            $this->fail();
        }
    }

    private function canonical(string $path): void
    {
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) {
                $this->fail();
            }
            if (file_exists($cursor)) {
                $actual = realpath($cursor);
                if ($actual === false || strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $cursor)) !== 0) {
                    $this->fail();
                }
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with(strtolower(str_replace('\\', '/', $path)), rtrim(strtolower(str_replace('\\', '/', $root)), '/').'/');
    }

    private function assertFinancialFingerprint(): void
    {
        if (! hash_equals(self::FINANCIAL_SCHEMA_SHA256, $this->financialSchema->fingerprint())) {
            $this->fail();
        }
    }

    private function assertAbsent(): void
    {
        if ((int) DB::scalar("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace AND left(relname,11)='proof_scan_'") !== 0
            || (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace AND left(proname,11)='proof_scan_'") !== 0
            || (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid='public.payments'::regclass AND tgname='proof_scan_payment_initial_final'") !== 0) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw $this->unavailable;
    }
}
