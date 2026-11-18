<?php

// Outer launcher never initializes Laravel. Every executable child starts with owned ini/temp/storage.
require_once __DIR__.'/FrameworkRuntimeIsolation.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;

$mode = $argv[1] ?? 'preflight';
if (! in_array($mode, ['preflight', 'targeted', 'browser', 'full', 'unit', 'general', 'frontend', 'pint', 'format', 'build'], true)) {
    fwrite(STDERR, "Mode runtime tidak dikenal.\n");
    exit(1);
}
$base = dirname(__DIR__, 2);
$resume = $argv[2] ?? null;
$new = $resume === null;
$root = $resume ?? realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'silansia-p94e1-'.bin2hex(random_bytes(8));
try {
    if ($new) {
        if (! mkdir($root, 0700)) {
            throw new LogicException('runtime_root_create_failed');
        }
        $root = realpath($root);
        Runtime::canonical($root);
        $owner = ['root' => $root, 'token' => bin2hex(random_bytes(32))];
        file_put_contents($root.'/owner.json', json_encode($owner, JSON_THROW_ON_ERROR), LOCK_EX);
        foreach (['tmp', 'uploads', 'ini', 'environment', 'cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'node-cache', 'npm-cache'] as $directory) {
            if (! mkdir($root.'/'.$directory, 0700, true) && ! is_dir($root.'/'.$directory)) {
                throw new LogicException('runtime_directory_create_failed');
            }
        }
        // Compare protected files with the user-requested pre-change baseline; never read/export their bodies.
        $baseline = json_decode(ltrim(file_get_contents($base.'/docs/report/SILANSIA_P9_4E_1_BASELINE.json'), "\xef\xbb\xbf"), true, flags: JSON_THROW_ON_ERROR);
        foreach ($baseline['protected_storage'] as $entry) {
            if (isset($entry['sha256']) && (! is_file($entry['path']) || strtolower($entry['sha256']) !== hash_file('sha256', $entry['path']))) {
                throw new LogicException('runtime_protected_storage_changed');
            }
        }
        file_put_contents($root.'/protected.json', json_encode(Runtime::protectedSnapshot(), JSON_THROW_ON_ERROR));
        $path = static fn ($value) => str_replace('\\', '/', $value);
        $ini = ['sys_temp_dir' => $root.'/tmp', 'upload_tmp_dir' => $root.'/uploads', 'session.save_path' => $root.'/storage/framework/sessions',
            'error_log' => $root.'/storage/logs/php-errors.log', 'auto_prepend_file' => __DIR__.'/isolated-runtime-prepend.php'];
        $lines = ['display_errors=0', 'log_errors=1', 'opcache.file_cache=""'];
        foreach ($ini as $key => $value) {
            $lines[] = $key.'="'.$path($value).'"';
        }
        file_put_contents($root.'/ini/zz-silansia-runtime.ini', implode(PHP_EOL, $lines).PHP_EOL);
    } else {
        Runtime::canonical($root);
        $root = realpath($root);
        if (! preg_match('/\Asilansia-p94e1-[a-f0-9]{16}\z/', basename($root))) {
            throw new LogicException('runtime_root_invalid');
        }
        $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($owner['root'] ?? null) !== $root || ! preg_match('/\A[a-f0-9]{64}\z/', $owner['token'] ?? '')) {
            throw new LogicException('runtime_owner_invalid');
        }
    }
    $scanned = php_ini_scanned_files();
    $scanDirectories = $scanned ? array_values(array_unique(array_map('dirname', preg_split('/,\s*/', trim($scanned))))) : [];
    $scanDirectories[] = $root.'/ini';
    $env = ['SILANSIA_P94E1_VERIFY' => '1', 'SILANSIA_RUNTIME_ROOT' => $root, 'SILANSIA_RUNTIME_OWNER' => $owner['token'],
        'PHP_INI_SCAN_DIR' => implode(PATH_SEPARATOR, $scanDirectories), 'TMP' => $root.'/tmp', 'TEMP' => $root.'/tmp', 'TMPDIR' => $root.'/tmp',
        'LARAVEL_STORAGE_PATH' => $root.'/storage', 'VIEW_COMPILED_PATH' => $root.'/storage/framework/views', 'LOG_CHANNEL' => '"null"',
        'APP_CONFIG_CACHE' => $root.'/cache/config.php', 'APP_SERVICES_CACHE' => $root.'/cache/services.php', 'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
        'APP_ROUTES_CACHE' => $root.'/cache/routes.php', 'APP_EVENTS_CACHE' => $root.'/cache/events.php',
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
        'FILESYSTEM_DISK' => 'local', 'CACHE_STORAGE_DISK' => 'local', 'CACHE_STORAGE_PATH' => 'framework/cache/data',
        'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'INVOICE_ISSUANCE_ENABLED' => 'false', 'PAYMENT_CORE_ENABLED' => 'false',
        'npm_config_cache' => $root.'/npm-cache'];
    $run = substr(basename($root), -16);
    $results = [];
    $execute = function (array $command, string $name, array $overrides = [], int $expectedExit = 0, ?string $expectedReason = null) use ($base, $env, $run, &$results): void {
        // Marker ownership and protected readback are checked outside Laravel too.
        foreach (['SILANSIA_P94E1_VERIFY', 'SILANSIA_RUNTIME_ROOT', 'SILANSIA_RUNTIME_OWNER'] as $key) {
            putenv($key.'='.$env[$key]);
        }
        Runtime::assertProtected();
        $process = new Process($command, $base, array_replace($env, $overrides));
        $process->setTimeout($name === 'full' ? 3600 : 900);
        $logPath = 'docs/report/SILANSIA_P9_4E_1_'.$name.'_'.$run.'_'.bin2hex(random_bytes(3)).'.log';
        file_put_contents($base.'/'.$logPath, '');
        $process->run(static function ($type, $buffer) use ($base, $logPath): void {
            file_put_contents($base.'/'.$logPath, $buffer, FILE_APPEND | LOCK_EX);
        });
        Runtime::assertProtected();
        $record = ['command' => $command, 'name' => $name, 'exit' => $process->getExitCode(), 'expected_exit' => $expectedExit,
            'protected_storage_unchanged' => true, 'log_path' => $logPath];
        if ($expectedReason !== null) {
            $value = json_decode($process->getOutput(), true);
            $record['reason'] = $value['reason'] ?? null;
            if (($value['ok'] ?? true) !== false || $record['reason'] !== $expectedReason) {
                throw new LogicException('runtime_negative_wrong_reason');
            }
        }
        $results[] = $record;
        if ($process->getExitCode() !== $expectedExit) {
            throw new LogicException('runtime_child_failed');
        }
    };
    // Every invocation first proves actual resolved handlers/paths, without fixture/migration/DB access.
    $execute([PHP_BINARY, 'tests/Support/framework-isolation-probe.php'], 'resolved');
    if ($mode === 'preflight') {
        foreach ([
            'missing_root' => [['SILANSIA_RUNTIME_ROOT' => false], 'runtime_context_missing'],
            'invalid_root' => [['SILANSIA_RUNTIME_ROOT' => $base.'/storage'], 'runtime_root_invalid'],
            'root_alias' => [['SILANSIA_RUNTIME_ROOT' => $root.'/../'.basename($root)], 'runtime_path_alias'],
            'lost_environment' => [['LARAVEL_STORAGE_PATH' => false], 'runtime_environment_missing'],
            'shared_storage' => [['LARAVEL_STORAGE_PATH' => $base.'/storage'], 'runtime_path_escape'],
            'missing_logger' => [['LOG_CHANNEL' => false], 'runtime_logger_environment'],
            'unquoted_null_logger' => [['LOG_CHANNEL' => 'null'], 'runtime_logger_environment'],
            'missing_directory' => [['VIEW_COMPILED_PATH' => $root.'/absent-views'], 'runtime_directory_unwritable'],
            'lost_ini' => [['PHP_INI_SCAN_DIR' => $root.'/environment'], 'runtime_prepend_missing'],
        ] as $name => [$overrides, $reason]) {
            $execute([PHP_BINARY, 'tests/Support/framework-isolation-probe.php'], 'negative_'.$name, $overrides, 78, $reason);
        }
        foreach (['view' => 'runtime_path_escape', 'cache' => 'runtime_path_escape', 'session' => 'runtime_path_escape',
            'logger_fallback' => 'runtime_logger_profile', 'logger_file' => 'runtime_logger_profile'] as $case => $reason) {
            $execute([PHP_BINARY, 'tests/Support/framework-isolation-probe.php', $case], 'negative_'.$case, [], 78, $reason);
        }
        $execute([PHP_BINARY, '-d', 'upload_tmp_dir='.$base.'/storage', 'tests/Support/framework-isolation-probe.php'], 'negative_upload', [], 78, 'runtime_path_escape');
        $link = $root.'/escape-junction';
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $script = '$ErrorActionPreference="Stop"; New-Item -ItemType Junction -Path $env:SILANSIA_LINK -Target $env:SILANSIA_LINK_TARGET | Out-Null';
                $maker = new Process(['powershell', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))], $base,
                    array_replace($env, ['SILANSIA_LINK' => $link, 'SILANSIA_LINK_TARGET' => $base.'/storage', 'PSModuleAnalysisCachePath' => $root.'/cache/powershell-modules']));
                $maker->mustRun();
            } else {
                symlink($base.'/storage', $link);
            }
            $reason = is_link($link) ? 'runtime_path_link' : 'runtime_path_alias';
            $execute([PHP_BINARY, 'tests/Support/framework-isolation-probe.php'], 'negative_junction', ['VIEW_COMPILED_PATH' => $link], 78, $reason);
        } finally {
            // Exact owned link only, never recursive and never follow/remove its protected target.
            if (is_link($link) || realpath($link) === realpath($base.'/storage')) {
                PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
            }
        }
        $execute([PHP_BINARY, 'tests/Support/run-owned-payment.php', '--payment-http-only'], 'negative_child_lost_context',
            ['SILANSIA_P94E1_VERIFY' => false, 'SILANSIA_RUNTIME_ROOT' => false, 'SILANSIA_RUNTIME_OWNER' => false, 'PHP_INI_SCAN_DIR' => $root.'/environment'], 78, 'runtime_context_missing');
        $execute([PHP_BINARY, 'tests/Support/run-payment-security.php', '--payment-http-only'], 'negative_legacy_entry_lost_context',
            ['SILANSIA_P94E1_VERIFY' => false, 'SILANSIA_RUNTIME_ROOT' => false, 'SILANSIA_RUNTIME_OWNER' => false, 'PHP_INI_SCAN_DIR' => $root.'/environment'], 78, 'runtime_context_missing');
    } elseif (in_array($mode, ['targeted', 'browser', 'full'], true)) {
        $command = [PHP_BINARY, 'tests/Support/run-owned-payment.php'];
        if ($mode !== 'full') {
            $command[] = $mode === 'targeted' ? '--payment-http-only' : '--payment-ui-only';
        }
        $execute($command, $mode);
    } elseif ($mode === 'unit' || $mode === 'general') {
        $execute([PHP_BINARY, 'vendor/bin/phpunit', '--configuration=phpunit.p94e1.xml', '--do-not-cache-result',
            '--cache-directory='.$root.'/cache/phpunit',
            ...($mode === 'unit' ? ['--testsuite=Unit'] : ['tests/Feature/ExampleTest.php'])], $mode);
    } elseif ($mode === 'pint' || $mode === 'format') {
        $command = [PHP_BINARY, 'vendor/bin/pint', '--cache-file='.$root.'/cache/pint.json'];
        if ($mode === 'pint') {
            $command[] = '--test';
        } else {
            $files = array_slice($GLOBALS['argv'], 3);
            if ($files === []) {
                throw new LogicException('runtime_formatter_scope');
            }
            $command = [...$command, ...$files];
            foreach ($files as $file) {
                if (! str_starts_with($file, 'tests/') || ! is_file($base.'/'.$file)
                    || ! str_starts_with(realpath($base.'/'.$file), realpath($base.'/tests').DIRECTORY_SEPARATOR)) {
                    throw new LogicException('runtime_formatter_scope');
                }
            }
        }
        $execute($command, $mode);
    } elseif ($mode === 'frontend') {
        $files = array_values(array_filter(glob('tests/Frontend/*.test.mjs'), 'is_file'));
        $execute(['node', '--import=./tests/Frontend/isolationPreflight.mjs', '--test', ...$files], $mode);
    } elseif ($mode === 'build') {
        $execute(['node', '--import=./tests/Frontend/isolationPreflight.mjs', 'tests/Frontend/buildIsolated.mjs'], $mode);
    }
    file_put_contents($base.'/docs/report/SILANSIA_P9_4E_1_COMMANDS_'.$mode.'_'.$run.'.json', json_encode(['root' => $root,
        'mode' => $mode, 'results' => $results, 'protected_storage_unchanged' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    // Root is retained, never recursively deleted by the launcher; reusable for the next verified stage.
    echo json_encode(['ok' => true, 'mode' => $mode, 'root' => $root, 'commands' => count($results)]).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'mode' => $mode, 'root' => $root, 'reason' => $error instanceof LogicException ? $error->getMessage() : 'runtime_launcher_failure']).PHP_EOL;
    exit(1);
}
