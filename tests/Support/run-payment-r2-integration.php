<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\BillingC2Preflight;
use Tests\Support\BillingC3Admission;
use Tests\Support\BillingC3Bootstrap;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

if (PHP_SAPI === 'cli' && in_array($argv[1] ?? '', ['--authorized-billing-c3', '--billing-c3-owned-child', '--billing-c3-control'], true)) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    require __DIR__.'/BillingC3Bootstrap.php';
    try {
        $command = BillingC3Bootstrap::command(array_slice($argv, 1));
        $manifest = $command === 'parent' ? $argv[5] : (getenv('SILANSIA_BILLING_C3_MANIFEST') ?: '');
        BillingC3Bootstrap::admit(dirname(__DIR__, 2), $manifest, $command === 'parent');
    } catch (Throwable $error) {
        $reason = $error instanceof LogicException && preg_match('/\Abilling_c3_[a-z0-9_]+\z/', $error->getMessage()) === 1
            ? $error->getMessage() : 'billing_c3_dependency_bootstrap_failed';
        echo json_encode(['status' => 'BLOCKED', 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(78);
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';

    exit(BillingC3Admission::entry(array_slice($argv, 1)));
}

if (PHP_SAPI === 'cli' && in_array($argv[1] ?? '', [
    '--authorized-billing-c2', '--billing-c2-owned-child', '--authorized-billing-demo', '--billing-demo-owned-child',
], true)) {
    ini_set('display_errors', '0');
    if (in_array($argv[1], ['--authorized-billing-c2', '--authorized-billing-demo'], true)) {
        ini_set('log_errors', '0');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';

    exit(BillingC2Preflight::entry(array_slice($argv, 1)));
}

// R1 prepares this entry point. Invocation requires a separate approval for 05E-2.
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || $argv[1] !== '--authorized-05e2'
    || ! preg_match('/\A(?:family|admin|admin-replay|reject|no-policy|queued|held|infected|stale|missing|scanner-off|missing-verdict|stale-envelope|wrong-subject|wrong-generation|ack-lost|ack-absent|timeout-committed|timeout-absent|reload|session|tabs|expired|late|navigation|locks-unavailable|storage-unavailable|security|ordering)\z/', $argv[2])) {
    fwrite(STDERR, "Pilih satu skenario dan gunakan izin eksplisit 05E-2.\n");
    exit(78);
}

ini_set('display_errors', '0');
require dirname(__DIR__, 2).'/vendor/autoload.php';

$base = dirname(__DIR__, 2);
$scenario = $argv[2];
$run = bin2hex(random_bytes(8));
$ownerToken = bin2hex(random_bytes(32));
$webKey = bin2hex(random_bytes(32));
$dbToken = bin2hex(random_bytes(32));
$root = null;
$neutral = null;
$identity = null;
$ownedReceipt = null;
$createAttempted = false;
$server = null;
$browserUnconfirmed = false;
$childUnconfirmed = false;
$stage = 'preflight';
$record = ['invocation' => $run, 'fixture' => $scenario, 'status' => 'BLOCKED', 'cleanup' => 'NOT CREATED'];

try {
    Identity::pins($base);
    $name = Identity::databaseFor($run);
    $host = '127.0.0.1';
    $port = getenv('SILANSIA_05E_DB_PORT') ?: '';
    $owner = getenv('SILANSIA_05E_DB_OWNER') ?: '';
    $password = getenv('SILANSIA_05E_DB_PASSWORD') ?: '';
    Identity::require($port !== '' && $owner !== '' && $password !== '', 'explicit_neutral_config_required');
    $temp = realpath(sys_get_temp_dir());
    Identity::require(is_string($temp), 'temp_root_required');
    Runtime::canonical($temp);
    $root = $temp.DIRECTORY_SEPARATOR.'silansia-p94e1-'.$run;
    Identity::require(! str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $base)).'/')
        && mkdir($root, 0700), 'owned_root_create_failed');
    Runtime::canonical($root);
    Identity::writeNew($root.'/owner.json', json_encode(['root' => $root, 'token' => $ownerToken], JSON_THROW_ON_ERROR));
    foreach (['tmp', 'uploads', 'ini', 'environment', 'cache', 'public/build', 'chrome', 'artifacts', 'node-cache', 'storage/app/private',
        'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
        Identity::require(mkdir($root.'/'.$dir, 0700, true), 'owned_directory_create_failed');
    }
    putenv('SILANSIA_RUNTIME_ROOT='.$root);
    putenv('SILANSIA_RUNTIME_OWNER='.$ownerToken);
    putenv('SILANSIA_P94E1_VERIFY=1');
    // The parent never trusts a previous run's identity.
    putenv('SILANSIA_TEST_IDENTITY');
    Identity::write($root.'/protected.json', Runtime::protectedSnapshot());
    ini_set('error_log', $root.'/storage/logs/parent-errors.log');
    $cacheDir = $root.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'silansia-phase1-'.$run;
    Identity::require(mkdir($cacheDir, 0700), 'owned_cache_create_failed');
    $cache = $cacheDir.DIRECTORY_SEPARATOR.'config.php';
    $ini = [
        'sys_temp_dir' => $root.'/tmp', 'upload_tmp_dir' => $root.'/uploads', 'session.save_path' => $root.'/storage/framework/sessions',
        'error_log' => $root.'/storage/logs/php-errors.log', 'auto_prepend_file' => __DIR__.'/isolated-runtime-prepend.php',
    ];
    $lines = ['display_errors=0', 'log_errors=1', 'zend.exception_ignore_args=1', 'opcache.file_cache=""'];
    foreach ($ini as $key => $value) {
        Identity::require(! preg_match('/["\r\n]/', $value), 'invalid_owned_ini_path');
        $lines[] = $key.'="'.str_replace('\\', '/', $value).'"';
    }
    Identity::writeNew(Runtime::path($root.'/ini/zz-05e-runtime.ini'), implode(PHP_EOL, $lines).PHP_EOL);
    $scanned = php_ini_scanned_files();
    $scan = $scanned ? array_values(array_unique(array_map('dirname', preg_split('/,\s*/', trim($scanned))))) : [];
    $scan[] = $root.'/ini';
    // Symfony Process merges its default environment. Explicitly unset every inherited key first.
    $system = array_intersect_key(getenv(), array_flip(['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir',
        'COMSPEC', 'ComSpec', 'PATHEXT', 'ProgramFiles', 'PROGRAMFILES', 'ProgramFiles(x86)', 'LOCALAPPDATA', 'HOME']));
    $environment = array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), $system, [
        'PHP_INI_SCAN_DIR' => implode(PATH_SEPARATOR, $scan), 'SILANSIA_P94E1_VERIFY' => '1',
        'SILANSIA_RUNTIME_ROOT' => $root, 'SILANSIA_RUNTIME_OWNER' => $ownerToken, 'SILANSIA_05E_RUN' => $run,
        'SILANSIA_05E_STAGE' => 'authorized-05e2', 'SILANSIA_05E_PHP' => PHP_BINARY, 'SILANSIA_05E_SCENARIO' => $scenario,
        'TMP' => $root.'/tmp', 'TEMP' => $root.'/tmp', 'TMPDIR' => $root.'/tmp', 'npm_config_cache' => $root.'/node-cache',
        'LARAVEL_STORAGE_PATH' => $root.'/storage', 'VIEW_COMPILED_PATH' => $root.'/storage/framework/views', 'LOG_CHANNEL' => '"null"',
        'APP_CONFIG_CACHE' => $cache, 'APP_SERVICES_CACHE' => $root.'/cache/services.php', 'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
        'APP_ROUTES_CACHE' => $root.'/cache/routes.php', 'APP_EVENTS_CACHE' => $root.'/cache/events.php', 'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_PREVIOUS_KEYS' => '', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'silansia_testing', 'DB_DATABASE' => '', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1',
        'SILANSIA_TEST_DB_DATABASE' => '', 'SILANSIA_TEST_DB_HOST' => $host, 'SILANSIA_TEST_DB_PORT' => $port,
        'SILANSIA_TEST_DB_SSLMODE' => 'disable', 'SILANSIA_TEST_DB_USERNAME' => $owner, 'SILANSIA_TEST_DB_PASSWORD' => '',
        'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'silansia_05e_'.$run, 'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'FILESYSTEM_DISK' => 'local', 'BCRYPT_ROUNDS' => '4',
        'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'INVOICE_ISSUANCE_ENABLED' => 'false', 'PAYMENT_CORE_ENABLED' => 'false',
        'SILANSIA_ACADEMIC_PROOF_SCAN' => '1',
    ]);
    $process = static function (array $args, array $env, int $seconds, ?string $input = null) use ($base): Process {
        Runtime::assertProtected();
        $child = new Process($args, $base, $env, $input, $seconds);
        $child->setOptions(['create_process_group' => true]);

        return $child;
    };
    $call = static function (array $args, array $env, int $seconds, ?string $input = null) use ($process, &$childUnconfirmed): array {
        $child = $process($args, $env, $seconds, $input);
        $childUnconfirmed = true;
        $child->run();
        Identity::require(! $child->isRunning() && $child->getExitCode() !== null, 'child_exit_not_confirmed');
        $childUnconfirmed = false;
        Runtime::assertProtected();
        $raw = $child->getOutput();
        Identity::require(strlen($raw) <= 262144, 'child_protocol_limit');

        return [$child->getExitCode(), json_decode($raw, true, 16, JSON_THROW_ON_ERROR)];
    };
    $stage = 'framework_preconnect';
    [$exit, $preflight] = $call([PHP_BINARY, 'tests/Support/payment-r2-integration-child.php', 'preflight'], $environment, 20);
    Identity::require($exit === 0 && ($preflight['ok'] ?? false) && ($preflight['preconnect'] ?? false)
        && is_string($preflight['cache'] ?? null)
        && str_replace('\\', '/', $preflight['cache']) === str_replace('\\', '/', $cache), 'framework_preconnect_failed');
    $cache = $preflight['cache'];
    $environment['APP_CONFIG_CACHE'] = $cache;

    $stage = 'build_owned_assets';
    [$exit, $assets] = $call(['node', 'tests/Browser/payment-r2-integration-assets.mjs'], $environment, 120);
    Identity::require($exit === 0 && ($assets['ok'] ?? false) === true, 'owned_asset_build_failed');

    $stage = 'create_owned_database';
    $neutral = Identity::neutral($host, $port, $owner, $password);
    Identity::require(Identity::catalog($neutral, $name) === false, 'database_target_already_exists');
    $createAttempted = true;
    $record['cleanup'] = 'OWNERSHIP UNVERIFIED — PRESERVED';
    $neutral->exec('CREATE DATABASE "'.$name.'"');
    $row = Identity::catalog($neutral, $name);
    Identity::require(is_array($row) && ctype_digit($row['oid']) && $row['owner'] === $owner && $row['marker'] === null,
        'new_database_identity_missing');
    $address = (string) $neutral->query('SELECT inet_server_addr()::text')->fetchColumn();
    $identity = ['database' => $name, 'oid' => $row['oid'], 'owner' => $owner, 'address' => $address, 'port' => $port,
        'host' => $host, 'connection_port' => $port, 'token' => $dbToken, 'cache' => $cache, 'runtime_root' => $root];
    $neutral->exec('COMMENT ON DATABASE "'.$name.'" IS '.$neutral->quote('silansia-phase1:'.$dbToken));
    Identity::verifyCatalog($neutral, $identity);
    $ownedReceipt = Identity::receipt($run, $identity, $ownerToken);
    Identity::write($root.'/database-receipt.json', $ownedReceipt);
    $record['database'] = ['name' => $name, 'oid' => $row['oid'], 'receipt_digest' => $ownedReceipt['digest']];
    $environment = array_replace($environment, [
        'SILANSIA_TEST_IDENTITY' => json_encode($identity, JSON_THROW_ON_ERROR),
        'SILANSIA_TEST_DB_DATABASE' => $name, 'SILANSIA_TEST_DB_PASSWORD' => $password,
        'SILANSIA_05E_WEB_KEY' => $webKey,
    ]);
    $stage = 'migrate';
    [$exit, $schema] = $call([PHP_BINARY, 'tests/Support/payment-r2-integration-child.php', 'migrate'], $environment, 180);
    Identity::require($exit === 0 && ($schema['ok'] ?? false) === true, 'guarded_migrations_failed');
    $record['schema'] = $schema['schema'];
    $stage = 'prepare_synthetic_fixture';
    [$exit, $fixture] = $call([PHP_BINARY, 'tests/Support/payment-r2-integration-child.php', 'fixture', $scenario], $environment, 50);
    Identity::require($exit === 0 && ($fixture['ok'] ?? false) === true, 'synthetic_fixture_failed');

    $stage = 'bind_owned_web';
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    Identity::require(is_resource($socket), 'loopback_reservation_failed');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    Identity::require(is_string($address) && preg_match('/\A127\.0\.0\.1:[1-9][0-9]{0,4}\z/', $address) === 1
        && ! in_array($address, ['127.0.0.1:8000', '127.0.0.1:5187'], true), 'loopback_endpoint_invalid');
    $environment['APP_URL'] = 'http://'.$address;
    $environment['ASSET_URL'] = '';
    // Symfony Process wraps commands with cmd.exe on Windows. A native argv
    // launch makes this process handle and its PID identify the PHP listener.
    $serverLog = Runtime::path($root.'/storage/logs/web-process.log');
    $webCommand = [PHP_BINARY, '-S', $address, '-t', $root.'/public', $base.'/tests/Support/payment-r2-integration-server.php'];
    $server = proc_open($webCommand, [['pipe', 'r'], ['file', $serverLog, 'ab'], ['file', $serverLog, 'ab']], $pipes, $base,
        array_filter($environment, static fn ($value) => $value !== false), ['bypass_shell' => true, 'create_process_group' => true]);
    Identity::require(is_resource($server), 'web_process_launch_failed');
    fclose($pipes[0]);
    $runningServer = proc_get_status($server);
    Identity::require($runningServer['running'] && $runningServer['pid'] > 0, 'owned_web_process_not_running');
    $environment['SILANSIA_05E_WEB_PID'] = (string) $runningServer['pid'];
    $stage = 'browser_acceptance';
    $browserUnconfirmed = true;
    [$exit, $result] = $call(['node', 'tests/Browser/payment-r2-integration-browser.mjs', $scenario], $environment, $scenario === 'ordering' ? 420 : 180,
        json_encode($fixture, JSON_THROW_ON_ERROR));
    $browserUnconfirmed = ($result['closed'] ?? null) !== true;
    $record['browser'] = ['exit' => $exit, 'closed' => ! $browserUnconfirmed, 'checks' => array_values(array_intersect(
        range(1, 38), array_filter($result['checks'] ?? [], 'is_int')))];
    Identity::require($exit === 0 && ($result['ok'] ?? false) === true && ! $browserUnconfirmed, 'browser_fixture_failed');
    $record['status'] = 'SCENARIO CHECKS COMPLETED';
} catch (Throwable) {
    $record['reason_stage'] = $stage;
} finally {
    $stopped = true;
    if (is_resource($server)) {
        try {
            if (proc_get_status($server)['running']) {
                proc_terminate($server);
            }
            $deadline = microtime(true) + 5;
            while (proc_get_status($server)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            $stopped = ! proc_get_status($server)['running'];
            if ($stopped) {
                proc_close($server);
            }
        } catch (Throwable) {
            $stopped = false;
        }
    }
    if ($createAttempted) {
        try {
            Identity::require($stopped && ! $childUnconfirmed && ! $browserUnconfirmed && $ownedReceipt !== null && $identity !== null && $neutral !== null,
                'cleanup_process_or_receipt_unconfirmed');
            Runtime::assertProtected();
            Identity::assertNeutral($neutral, $host, $port, $owner);
            Identity::verifyReceipt(Identity::read($root.'/database-receipt.json'), $run, $identity, $ownerToken);
            Identity::verifyCatalog($neutral, $identity);
            $peers = $neutral->prepare('SELECT count(*) FROM pg_stat_activity WHERE datid=?::oid OR datname=?');
            $peers->execute([$identity['oid'], $identity['database']]);
            Identity::require((int) $peers->fetchColumn() === 0, 'cleanup_peers_present');
            Identity::verifyCatalog($neutral, $identity);
            $neutral->exec('DROP DATABASE "'.$identity['database'].'"');
            $postcheck = $neutral->prepare('SELECT count(*) FROM pg_database WHERE oid=?::oid OR datname=?');
            $postcheck->execute([$identity['oid'], $identity['database']]);
            Identity::require((int) $postcheck->fetchColumn() === 0, 'cleanup_postcheck_failed');
            $record['cleanup'] = 'OWNED DATABASE DROPPED';
        } catch (Throwable) {
            $record['status'] = 'BLOCKED';
        }
    }
    $neutral = null;
    // Keep the private root for review if any stage failed or child termination is uncertain.
    if ($root !== null && $record['status'] === 'SCENARIO CHECKS COMPLETED') {
        try {
            Runtime::assertProtected();
            Identity::pins($base);
            Identity::require(Runtime::root() === $root && realpath($root) === $root, 'cleanup_root_mismatch');
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST);
            $paths = [];
            foreach ($entries as $entry) {
                $path = Runtime::path($entry->getPathname(), $entry->isDir());
                Identity::require(! $entry->isLink() && ($entry->isDir() || $entry->isFile()), 'cleanup_entry_invalid');
                $paths[$path] = $entry->isDir();
            }
            // Keep the marker readable until every other entry has been verified and removed.
            $marker = $root.DIRECTORY_SEPARATOR.'owner.json';
            unset($paths[$marker]);
            foreach ($paths as $path => $directory) {
                Runtime::canonical($path);
                Identity::require($directory ? rmdir($path) : unlink($path), 'cleanup_root_entry_failed');
            }
            Identity::require(Runtime::root() === $root && unlink($marker) && rmdir($root), 'cleanup_root_failed');
            $record['root_removed'] = true;
        } catch (Throwable) {
            $record['status'] = 'BLOCKED';
        }
    }
    echo json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL;
}
exit($record['status'] === 'SCENARIO CHECKS COMPLETED' ? 0 : 1);
