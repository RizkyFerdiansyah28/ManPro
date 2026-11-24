<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use LogicException;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Throwable;

final class BillingC2Preflight
{
    private const CHILD_SETTINGS = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'silansia_testing',
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local', 'SILANSIA_ACADEMIC_PROOF_SCAN' => 'false',
        'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'INVOICE_ISSUANCE_ENABLED' => 'false', 'PAYMENT_CORE_ENABLED' => 'false',
    ];

    private const CHILD_FAILURE_CODES = [
        'billing_c2_child_environment_mismatch', 'billing_c2_external_database_environment', 'billing_c2_previous_keys_present',
        'billing_c2_inherited_postgres_environment', 'billing_c2_child_claim_invalid', 'billing_c2_parent_snapshot_mismatch',
        'billing_c2_cache_path_mismatch', 'billing_c2_framework_cache_present', 'billing_c2_framework_resolution_mismatch',
        'billing_c2_process_failed', 'billing_c2_process_timeout', 'billing_c2_preflight_failed', 'billing_c2_private_acl_unverified',
        'billing_c2_cluster_identity_mismatch', 'billing_c2_cluster_proof_mismatch', 'billing_c2_listener_identity_mismatch',
        'billing_c2_init_secret_cleanup_failed', 'billing_c2_shutdown_unconfirmed', 'source_pin_mismatch',
        'billing_c2_connection_scope_invalid', 'billing_c2_disconnect_failed', 'billing_c2_connection_release_unverified',
        'runtime_context_missing', 'runtime_environment_missing', 'runtime_protected_storage_changed',
        'runtime_directory_unwritable', 'runtime_path_escape', 'runtime_path_alias', 'runtime_path_link', 'runtime_prepend_missing',
    ];

    public static function entry(array $args, ?string $c3Manifest = null, ?BillingC3WindowsHelpers $windowsHelpers = null): int
    {
        $base = dirname(__DIR__, 2);
        $root = null;
        $stage = 'baseline';
        $childFacts = null;
        $demo = $c3Manifest === null && in_array($args[0] ?? '', ['--authorized-billing-demo', '--billing-demo-owned-child'], true);
        try {
            Identity::require(($c3Manifest === null) === ($windowsHelpers === null), 'billing_c3_windows_helper_profile_required');
            $childFlag = $c3Manifest === null ? '--billing-c2-owned-child' : '--billing-c3-owned-child';
            if ($demo) {
                $childFlag = '--billing-demo-owned-child';
            }
            if (count($args) === 2 && $args[0] === $childFlag) {
                $stage = 'child_environment';
                self::assertChildEnvironment();
                if ($c3Manifest !== null) {
                    self::registerC3Coordinator('CHILD');
                }
                $stage = 'baseline';
                $snapshot = BillingC2Baseline::verify($base);
                $root = Runtime::root();
                self::checkWindowsRootAcl($root, windowsHelpers: $windowsHelpers);
                Identity::require(Identity::read($root.'/protected.json') === $snapshot, 'billing_c2_parent_snapshot_mismatch');
                Identity::require(Identity::read($root.'/child-launch.json') === self::childClaim($args[1], $c3Manifest, $demo), 'billing_c2_child_claim_invalid');
                Identity::writeNew(Runtime::path($root.'/child.claim'), $demo ? BillingMinimumDemo::CHILD_CLAIM : 'owned-preflight');
                $stage = 'framework_no_database';
                $app = self::verifyFramework($base);
                $stage = 'owned_cluster';
                $cluster = new BillingC2Cluster($args[1], $root, $windowsHelpers);
                $c3 = $c3Manifest === null ? null : new BillingC3Runtime($c3Manifest, $root, $windowsHelpers);
                echo json_encode(['ok' => true, ...$cluster->verify($stage, $c3, $demo ? $app : null)], JSON_THROW_ON_ERROR).PHP_EOL;

                return 0;
            }

            $command = $c3Manifest === null ? ['--authorized-billing-c2', 'preflight'] : ['--authorized-billing-c3', 'e2e'];
            if ($demo) {
                $command = ['--authorized-billing-demo', 'run'];
            }
            Identity::require(count($args) === 3 && array_slice($args, 0, 2) === $command, 'billing_c2_invalid_command');
            $snapshot = BillingC2Baseline::verify($base);
            if ($c3Manifest !== null) {
                self::registerC3Coordinator('PARENT');
            }
            Identity::require(PHP_OS_FAMILY === 'Windows', 'billing_c2_windows_required');
            $bin = BillingC2Cluster::windowsPath($args[2]);
            Identity::require(is_dir($bin), 'billing_c2_postgres_bin_required');
            $temp = realpath(sys_get_temp_dir());
            Identity::require(is_string($temp), 'billing_c2_temp_root_required');
            $temp = BillingC2Cluster::windowsPath($temp);
            $stage = 'private_root';
            $run = bin2hex(random_bytes(8));
            $root = $temp.DIRECTORY_SEPARATOR.'silansia-p94e1-'.$run;
            Identity::require(! str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $base)).'/')
                && ! file_exists($root) && ! is_link($root) && mkdir($root, 0700), 'billing_c2_root_create_failed');
            Runtime::canonical($root);
            $owner = bin2hex(random_bytes(32));
            self::checkWindowsRootAcl($root, true, $windowsHelpers);
            Identity::writeNew($root.'/owner.json', json_encode(['root' => $root, 'token' => $owner], JSON_THROW_ON_ERROR));
            putenv('SILANSIA_P94E1_VERIFY=1');
            putenv('SILANSIA_RUNTIME_ROOT='.$root);
            putenv('SILANSIA_RUNTIME_OWNER='.$owner);
            Identity::write($root.'/protected.json', $snapshot);
            $env = self::childEnvironment($root, $run, $owner, $windowsHelpers);
            Identity::write($root.'/child-launch.json', self::childClaim($bin, $c3Manifest, $demo));
            if ($c3Manifest !== null) {
                $env['SILANSIA_BILLING_C3_MANIFEST'] = $c3Manifest;
                $env['SILANSIA_BILLING_C3_DEPENDENCY_POLICY'] = BillingC3Bootstrap::policyDigest();
                $env += BillingC3ProcessJob::settings();
            }
            Runtime::assertProtected();

            $stage = 'preflight_child';
            $childArgs = [$base.'/tests/Support/run-payment-r2-integration.php', $childFlag, $bin];
            $child = $c3Manifest === null ? [PHP_BINARY, ...$childArgs] : BillingC3Bootstrap::phpCommand($childArgs, $root);
            [$code, $output] = $c3Manifest === null
                ? self::runProcess($child, $root, $env, 300)
                : BillingC3Process::run($child, $root, $env, BillingC3ProcessJob::CHILD_SECONDS);
            $childFacts = json_decode($output, true, 8);
            $c3Checks = [];
            $demoResult = null;
            if ($demo && $code === 0 && is_array($childFacts) && ($childFacts['ok'] ?? null) === true) {
                $demoResult = BillingMinimumDemo::validateResult($childFacts['demo'] ?? null);
                unset($childFacts['demo']);
            }
            if ($c3Manifest !== null && is_array($childFacts) && ($childFacts['ok'] ?? null) === true) {
                $c3Checks = $childFacts['c3_checks'] ?? null;
                Identity::require($c3Checks === BillingC3Admission::RUNTIME_CHECKS, 'billing_c3_checks_incomplete');
                unset($childFacts['c3_checks']);
            }
            Identity::require($code === 0 && is_array($childFacts) && array_keys($childFacts) === [
                'ok', 'database_dropped', 'cluster_stopped', 'loopback_port', 'database_oid', 'negative_catalog_checks',
            ] && $childFacts['ok'] === true && $childFacts['database_dropped'] === true && $childFacts['cluster_stopped'] === true
                && is_string($childFacts['loopback_port']) && ctype_digit($childFacts['loopback_port'])
                && is_string($childFacts['database_oid']) && ctype_digit($childFacts['database_oid'])
                && $childFacts['negative_catalog_checks'] === 3, 'billing_c2_child_unverified');

            $stage = 'cleanup_private_root';
            BillingC2Baseline::verify($base);
            if ($c3Manifest !== null) {
                BillingC3Admission::verifyManifest($c3Manifest);
            }
            Runtime::assertProtected();
            self::checkWindowsRootAcl($root, windowsHelpers: $windowsHelpers);
            if ($c3Manifest !== null) {
                $job = BillingC3ProcessJob::open();
                $job->assertCleanupProcesses([$job->process(getmypid())]);
            }
            self::removePrivateRoot($root);
            if ($demo) {
                echo json_encode(['mode' => 'billing-minimum-demo', ...$demoResult, 'database_dropped' => true,
                    'cluster_stopped' => true, 'root_removed' => true], JSON_THROW_ON_ERROR).PHP_EOL;

                return $demoResult['status'] === 'PASS' ? 0 : 1;
            }
            echo json_encode(['status' => 'PASS', 'mode' => $c3Manifest === null ? 'billing-c2-preflight' : 'billing-c3-e2e', ...$childFacts,
                ...($c3Manifest === null ? [] : ['c3_checks' => $c3Checks, 'manifest_sha256' => $c3Manifest]), 'root_removed' => true], JSON_THROW_ON_ERROR).PHP_EOL;

            return 0;
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException && preg_match('/\A[a-z][a-z0-9_]{2,80}\z/', $error->getMessage())
                ? $error->getMessage() : 'billing_c2_preflight_failed';
            if ($demo) {
                echo json_encode(['status' => 'BLOCKED', 'mode' => 'billing-minimum-demo', 'stage' => $stage,
                    'reason' => BillingMinimumDemo::safeReason($reason), 'root_retained' => $root !== null && is_dir($root),
                    'invocation' => $root === null ? null : basename($root),
                    ...self::childFailureDetails($childFacts, demo: true)], JSON_THROW_ON_ERROR).PHP_EOL;

                return 78;
            }
            echo json_encode(['status' => 'BLOCKED', 'stage' => $stage, 'reason' => $reason, 'root' => $root,
                ...self::childFailureDetails($childFacts, $c3Manifest !== null)], JSON_THROW_ON_ERROR).PHP_EOL;
            if ($c3Manifest !== null) {
                BillingC3ProcessJob::signalFailure();
            }

            return 78;
        }
    }

    private static function registerC3Coordinator(string $role): void
    {
        $identity = BillingC3ProcessJob::open()->process(getmypid());
        putenv('SILANSIA_BILLING_C3_'.$role.'_PID='.$identity['pid']);
        putenv('SILANSIA_BILLING_C3_'.$role.'_BIRTH='.$identity['birth']);
    }

    public static function childFailureDetails(mixed $facts, bool $c3 = false, bool $demo = false): array
    {
        $blocked = is_array($facts) && ($facts['status'] ?? null) === 'BLOCKED';
        $stage = $blocked ? ($facts['stage'] ?? null) : null;
        $reason = $blocked ? ($facts['reason'] ?? null) : null;

        $stages = ['child_environment', 'baseline', ...BillingC2Cluster::STAGES];
        if ($demo) {
            $stages = [...$stages, ...BillingMinimumDemo::STAGES];
        }
        $c3Failure = $c3 && is_string($reason) && preg_match('/\Abilling_c3_[a-z0-9_]{1,70}\z/', $reason) === 1;

        return [
            'child_stage' => in_array($stage, $c3 ? [...$stages, ...BillingC3Runtime::STAGES] : $stages, true) ? $stage : null,
            'child_reason' => in_array($reason, self::CHILD_FAILURE_CODES, true) || $c3Failure
                || ($demo && is_string($reason) && BillingMinimumDemo::safeReason($reason) === $reason) ? $reason : null,
        ];
    }

    public static function checkWindowsRootAcl(string $root, bool $initialize = false, ?BillingC3WindowsHelpers $windowsHelpers = null): void
    {
        Runtime::canonical($root);
        if ($windowsHelpers === null) {
            $windows = getenv('SystemRoot');
            Identity::require(is_string($windows), 'billing_c2_system_root_required');
            $powershell = BillingC2Cluster::windowsPath($windows.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe');
        } else {
            $powershell = $windowsHelpers->executable('powershell');
        }
        // Apply and verify the DACL before writing a marker, log, or password.
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $root = $env:SILANSIA_RUNTIME_ROOT
            $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
            $inherit = [System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
            if ($env:SILANSIA_BILLING_C2_INIT_ACL -eq '1') {
                $acl = [System.Security.AccessControl.DirectorySecurity]::new()
                $acl.SetOwner($sid)
                $acl.SetAccessRuleProtection($true, $false)
                $acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', $inherit, 'None', 'Allow'))
                Set-Acl -LiteralPath $root -AclObject $acl
            }
            $actual = Get-Acl -LiteralPath $root
            $rules = @($actual.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]))
            if (!$actual.AreAccessRulesProtected -or $actual.GetOwner([System.Security.Principal.SecurityIdentifier]) -ne $sid -or
                $rules.Count -ne 1 -or $rules[0].IdentityReference -ne $sid -or $rules[0].IsInherited -or
                $rules[0].AccessControlType -ne 'Allow' -or $rules[0].FileSystemRights -ne 'FullControl' -or
                $rules[0].InheritanceFlags -ne $inherit -or $rules[0].PropagationFlags -ne 'None') { exit 78 }
            exit 0
            POWERSHELL;
        if ($windowsHelpers !== null) {
            $script = '$env:PSModulePath = $PSHOME + "\\Modules"'.PHP_EOL.$script;
        }
        $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
        $settings = ['SILANSIA_RUNTIME_ROOT' => $root, 'SILANSIA_BILLING_C2_INIT_ACL' => $initialize ? '1' : '0', 'TEMP' => $root, 'TMP' => $root,
            'PSModuleAnalysisCachePath' => $root.'/powershell-modules'];
        $command = [$powershell, '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded];
        [$code] = $windowsHelpers === null
            ? self::runProcess($command, $root, self::restrictedEnvironment($settings), 20, 0)
            : BillingC3Process::run($command, $root, $windowsHelpers->environment($settings), 20, 0, afterRevocation: true);
        Identity::require($code === 0, 'billing_c2_private_acl_unverified');
    }

    public static function restrictedEnvironment(array $extra): array
    {
        $windows = array_intersect_key(getenv(), array_flip(['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir', 'COMSPEC', 'ComSpec']));

        return array_replace($windows, $extra);
    }

    public static function runProcess(array $command, string $root, array $env, int $seconds, int $maxOutput = 65536): array
    {
        // Native argv and files under our root avoid cmd.exe and Symfony WindowsPipes' shared temp files.
        $stdout = $stderr = $process = null;
        try {
            if ($maxOutput > 0) {
                Identity::require(Runtime::root() === $root, 'billing_c2_process_root_unverified');
                $prefix = $root.'/child-'.bin2hex(random_bytes(8));
                $stdout = fopen(Runtime::path($prefix.'.stdout'), 'x+b');
                $stderr = fopen(Runtime::path($prefix.'.stderr'), 'x+b');
                Identity::require(is_resource($stdout) && is_resource($stderr), 'billing_c2_process_output_failed');
            }
            $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => $stdout ?? ['file', 'NUL', 'w'],
                2 => $stderr ?? ['file', 'NUL', 'w']], $pipes, $root, $env, ['bypass_shell' => true, 'create_process_group' => true]);
            Identity::require(is_resource($process), 'billing_c2_process_launch_failed');
            $deadline = hrtime(true) + $seconds * 1_000_000_000;
            while (($status = proc_get_status($process))['running'] && hrtime(true) < $deadline) {
                usleep(25000);
            }
            if ($status['running']) {
                // Only signal the just-spawned command handle. The cluster and private root remain for review.
                proc_terminate($process);
                throw new LogicException('billing_c2_process_timeout');
            }
            proc_close($process);
            Identity::require($status['exitcode'] >= 0, 'billing_c2_process_exit_unverified');
            if ($stdout === null) {
                return [$status['exitcode'], ''];
            }
            Identity::require(fstat($stdout)['size'] <= $maxOutput && rewind($stdout), 'billing_c2_process_output_limit');
            $output = stream_get_contents($stdout, $maxOutput + 1);
            Identity::require(is_string($output) && strlen($output) <= $maxOutput, 'billing_c2_process_output_limit');

            return [$status['exitcode'], $output];
        } finally {
            foreach ([$stdout, $stderr] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    private static function childClaim(string $bin, ?string $c3Manifest = null, bool $demo = false): array
    {
        $claim = ['root' => Runtime::root(), 'bin' => BillingC2Cluster::windowsPath($bin)];
        if ($c3Manifest !== null) {
            $claim['c3_manifest'] = $c3Manifest;
            $claim['c3_dependency_policy'] = BillingC3Bootstrap::policyDigest();
        }
        if ($demo) {
            $claim['functional_demo'] = true;
        }
        $claim['digest'] = hash_hmac('sha256', json_encode($claim, JSON_THROW_ON_ERROR), getenv('SILANSIA_RUNTIME_OWNER'));

        return $claim;
    }

    /** The PHP INI setting and the cache path must share the guard's native prefix. */
    public static function cacheConfiguration(string $root, string $run): array
    {
        $temp = $root.DIRECTORY_SEPARATOR.'tmp';
        Identity::require(! preg_match('/["\r\n]/', $temp), 'billing_c2_unsafe_ini_path');

        return ['temp' => $temp, 'ini' => 'sys_temp_dir="'.$temp.'"',
            'cache' => $temp.DIRECTORY_SEPARATOR.'silansia-phase1-'.$run.DIRECTORY_SEPARATOR.'config.php'];
    }

    public static function assertCachePreconditions(): string
    {
        clearstatcache(true);
        $root = Runtime::root();
        $run = getenv('SILANSIA_BILLING_C2_RUN') ?: '';
        Identity::databaseFor($run);
        ['temp' => $temp, 'cache' => $cache] = self::cacheConfiguration($root, $run);
        // Check actual PHP spelling and the exact invocation path before consulting the filesystem.
        Identity::require(BillingC2Cluster::windowsPath($root) === $root && basename($root) === 'silansia-p94e1-'.$run
            && sys_get_temp_dir() === $temp && getenv('APP_CONFIG_CACHE') === $cache, 'billing_c2_cache_path_mismatch');
        Runtime::path($temp, true);
        Runtime::path(dirname($cache), true);
        Runtime::path($cache);
        Identity::require(! file_exists($cache) && ! is_link($cache), 'billing_c2_framework_cache_present');

        return $cache;
    }

    private static function childEnvironment(string $root, string $run, string $owner, ?BillingC3WindowsHelpers $windowsHelpers): array
    {
        foreach (['tmp/silansia-phase1-'.$run, 'uploads', 'ini', 'environment', 'cache', 'public', 'storage/app/private', 'storage/app/public',
            'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
            $path = $root.'/'.$directory;
            Runtime::canonical($path);
            Identity::require(mkdir($path, 0700, true), 'billing_c2_directory_create_failed');
            Runtime::path($path, true);
        }
        ini_set('error_log', $root.'/storage/logs/parent-errors.log');
        ini_set('log_errors', '1');
        if ($windowsHelpers !== null) {
            return self::childProcessEnvironment($root, $run, $owner, '', $windowsHelpers);
        }
        $configuration = self::cacheConfiguration($root, $run);
        $ini = ['upload_tmp_dir' => $root.'/uploads', 'session.save_path' => $root.'/storage/framework/sessions',
            'error_log' => $root.'/storage/logs/php-errors.log', 'auto_prepend_file' => __DIR__.'/isolated-runtime-prepend.php'];
        $lines = ['display_errors=0', 'log_errors=1', 'zend.exception_ignore_args=1', 'opcache.file_cache=""', $configuration['ini']];
        foreach ($ini as $key => $value) {
            Identity::require(! preg_match('/["\r\n]/', $value), 'billing_c2_unsafe_ini_path');
            $lines[] = $key.'="'.str_replace('\\', '/', $value).'"';
        }
        Identity::writeNew(Runtime::path($root.'/ini/zz-billing-c2.ini'), implode(PHP_EOL, $lines).PHP_EOL);
        $scanned = php_ini_scanned_files();
        $scan = $scanned ? array_values(array_unique(array_map('dirname', preg_split('/,\s*/', trim($scanned))))) : [];
        $scan[] = $root.'/ini';

        return self::childProcessEnvironment($root, $run, $owner, implode(PATH_SEPARATOR, $scan), $windowsHelpers);
    }

    public static function childProcessEnvironment(string $root, string $run, #[\SensitiveParameter] string $owner, string $iniScanDir,
        ?BillingC3WindowsHelpers $windowsHelpers = null): array
    {
        // Native proc_open drops empty array values. The child requires forbidden settings to be absent.
        $settings = self::CHILD_SETTINGS + [
            ...($windowsHelpers === null ? ['PHP_INI_SCAN_DIR' => $iniScanDir] : []),
            'SILANSIA_P94E1_VERIFY' => '1', 'SILANSIA_RUNTIME_ROOT' => $root,
            'SILANSIA_RUNTIME_OWNER' => $owner, 'SILANSIA_BILLING_C2_RUN' => $run,
            'TMP' => $root.'/tmp', 'TEMP' => $root.'/tmp', 'TMPDIR' => $root.'/tmp',
            'LARAVEL_STORAGE_PATH' => $root.'/storage', 'VIEW_COMPILED_PATH' => $root.'/storage/framework/views', 'LOG_CHANNEL' => '"null"',
            'APP_CONFIG_CACHE' => self::cacheConfiguration($root, $run)['cache'],
            'APP_SERVICES_CACHE' => $root.'/cache/services.php', 'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
            'APP_ROUTES_CACHE' => $root.'/cache/routes.php', 'APP_EVENTS_CACHE' => $root.'/cache/events.php',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ];

        return $windowsHelpers?->environment($settings) ?? self::restrictedEnvironment($settings);
    }

    private static function assertChildEnvironment(): void
    {
        Runtime::environment();
        $run = getenv('SILANSIA_BILLING_C2_RUN') ?: '';
        Identity::databaseFor($run);
        Identity::require(PHP_OS_FAMILY === 'Windows' && basename(Runtime::root()) === 'silansia-p94e1-'.$run,
            'billing_c2_child_environment_mismatch');
        self::assertChildSettings();
    }

    public static function assertChildSettings(): void
    {
        foreach (self::CHILD_SETTINGS as $key => $value) {
            Identity::require(getenv($key) === $value, 'billing_c2_child_environment_mismatch');
        }
        Identity::require(preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', getenv('APP_KEY') ?: '') === 1,
            'billing_c2_child_environment_mismatch');
        Identity::require(getenv('APP_PREVIOUS_KEYS') === false, 'billing_c2_previous_keys_present');
        foreach (array_keys(getenv()) as $key) {
            $key = strtoupper((string) $key);
            Identity::require(! str_starts_with($key, 'PG'), 'billing_c2_inherited_postgres_environment');
            Identity::require(($key === 'DB_CONNECTION' || ! str_starts_with($key, 'DB_'))
                && ! str_starts_with($key, 'SILANSIA_TEST_DB_') && ! str_starts_with($key, 'SILANSIA_05E_DB_')
                && ! in_array($key, ['DATABASE_URL', 'SILANSIA_TEST_IDENTITY'], true), 'billing_c2_external_database_environment');
        }
    }

    private static function verifyFramework(string $base): Application
    {
        $root = Runtime::root();
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            $path = Runtime::path(getenv($key));
            Identity::require(! file_exists($path) && ! is_link($path), 'billing_c2_framework_cache_present');
        }
        $environment = Runtime::path($root.'/environment', true);
        Identity::require(scandir($environment) === ['.', '..'], 'billing_c2_dotenv_must_be_absent');
        $app = require $base.'/bootstrap/app.php';
        $app->useEnvironmentPath($environment);
        $app->loadEnvironmentFrom('billing-c2-absent.env');
        $app->usePublicPath(Runtime::path($root.'/public', true));
        Runtime::attach($app);
        // Deny connection creation before Laravel can run any provider that might use it.
        $app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
            $app['config']->set('database.connections', []);
        });
        $app->make(Kernel::class)->bootstrap();
        $record = Runtime::resolved($app);
        Identity::require($app->environment('testing') && ! $app->configurationIsCached()
            && $app['db']->getConnections() === [] && $app['config']->get('database.connections') === []
            && $app['config']->get('database.default') === 'silansia_testing'
            && $app['config']->get('app.key') === getenv('APP_KEY') && $app['config']->get('app.previous_keys') === []
            && $record['logger_channel'] === 'null' && $app['config']->get('cache.default') === 'array'
            && $app['config']->get('session.driver') === 'array' && $app['config']->get('payment_http.enabled') === false
            && $app['config']->get('payment_core.enabled') === false && $app['config']->get('invoice_issuance.enabled') === false
            && $app['config']->get('financial_ownership.enabled') === false, 'billing_c2_framework_resolution_mismatch');
        Runtime::assertProtected();

        return $app;
    }

    private static function removePrivateRoot(string $root): void
    {
        Identity::require(Runtime::root() === $root && ! file_exists($root.'/postgres-data/postmaster.pid')
            && ! is_link($root.'/postgres-data/postmaster.pid'), 'billing_c2_cleanup_root_unverified');
        $scan = static function (string $directory) use (&$scan): array {
            $entries = [];
            foreach (array_diff(scandir($directory), ['.', '..']) as $name) {
                $path = Runtime::path($directory.DIRECTORY_SEPARATOR.$name);
                $isDirectory = is_dir($path);
                Identity::require($isDirectory || is_file($path), 'billing_c2_cleanup_invalid_entry');
                if ($isDirectory) {
                    $entries += $scan($path);
                }
                $entries[$path] = $isDirectory;
            }

            return $entries;
        };
        $entries = $scan($root);
        $marker = $root.DIRECTORY_SEPARATOR.'owner.json';
        unset($entries[$marker]);
        foreach ($entries as $path => $directory) {
            Runtime::canonical($path);
            Identity::require($directory ? rmdir($path) : unlink($path), 'billing_c2_cleanup_entry_failed');
        }
        Identity::require(Runtime::root() === $root && unlink($marker) && rmdir($root), 'billing_c2_cleanup_root_failed');
    }
}
