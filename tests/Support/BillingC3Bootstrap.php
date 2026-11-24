<?php

declare(strict_types=1);

namespace Tests\Support;

use FFI;
use LogicException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** C3's pre-autoload barrier. This file uses only PHP/Win32 primitives. */
final class BillingC3Bootstrap
{
    public const POLICY = 'tests/Support/billing-c3-dependency-policy.json';

    public const MANIFEST = 'tests/Support/billing-c3-source-manifest.json';

    public const EXTENSIONS = ['curl', 'ffi', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_pgsql'];

    public const TARGETS = ['primary', 'foreign', 'transfer', 'invalid-format', 'invalid-dimensions', 'tamper',
        'rejection', 'unknown', 'stale', 'ttl', 'verify-loss', 'lost', 'absent', 'session', 'zero', 'cancelled'];

    private const CORE_MODULES = ['bcmath', 'calendar', 'core', 'ctype', 'date', 'dom', 'filter', 'hash', 'iconv', 'json', 'lexbor',
        'libxml', 'mysqlnd', 'pcre', 'pdo', 'phar', 'random', 'readline', 'reflection', 'session', 'simplexml', 'spl', 'standard',
        'tokenizer', 'uri', 'xml', 'xmlreader', 'xmlwriter', 'zend opcache', 'zlib'];

    private const HOST_ROOTS = ['php', 'node', 'chrome', 'postgres-bin', 'postgres-lib', 'postgres-share'];

    private static ?array $verified = null;

    public static function command(array $args): string
    {
        return match (true) {
            count($args) === 5 && $args[0] === '--authorized-billing-c3' && $args[1] === 'e2e' && $args[2] !== ''
                && $args[3] === '--approved-manifest' && self::validHash($args[4]) => 'parent',
            count($args) === 2 && $args[0] === '--billing-c3-owned-child' && $args[1] !== '' => 'child',
            ($args[0] ?? null) === '--billing-c3-control' && self::validAction(array_slice($args, 1)) => 'control',
            default => throw new LogicException('billing_c3_invalid_arguments'),
        };
    }

    public static function validAction(array $args): bool
    {
        $action = $args[0] ?? '';
        if (in_array($action, ['migrate', 'fixture', 'acceptance', 'acceptance-initial'], true)) {
            return count($args) === 1;
        }
        $target = $args[1] ?? '';
        $uuid = static fn (string $id): bool => preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id) === 1;

        return match ($action) {
            'before' => count($args) === 2 && in_array($target, self::TARGETS, true),
            'bind' => count($args) === 3 && in_array($target, self::TARGETS, true) && $uuid($args[2]),
            'observe', 'transfer', 'cancelled-fixture', 'race', 'expire-context', 'terminal-context' => count($args) === 2 && $uuid($target),
            'race-worker' => count($args) === 3 && $uuid($target) && in_array($args[2], ['a', 'b'], true),
            'scan', 'visibility', 'stale', 'verdict-freshness' => count($args) === 3 && $uuid($target) && $uuid($args[2]),
            default => false,
        };
    }

    public static function admit(string $base, string $manifestDigest, bool $parent): void
    {
        self::assertEnvironment(getenv());
        self::check(PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8 && php_ini_loaded_file() === false
            && php_ini_scanned_files() === false && ini_get('auto_prepend_file') === '' && ini_get('auto_append_file') === ''
            && ini_get('opcache.enable_cli') === '0' && ini_get('opcache.enable') === '0'
            && ini_get('opcache.file_cache') === '' && ini_get('opcache.preload') === ''
            && get_include_path() === '.', 'billing_c3_php_boot_profile_mismatch');
        self::assertExtensions(get_loaded_extensions());
        $jobFile = $base.'/tests/Support/BillingC3ProcessJob.php';
        $digest = getenv('SILANSIA_BILLING_C3_DEPENDENCY_POLICY') ?: '';
        $policy = self::readPinnedJson($base.'/'.self::POLICY, $digest);
        $manifest = self::readPinnedJson($base.'/'.self::MANIFEST, $manifestDigest);
        self::assertSourcePins($base, ['tests/Support/BillingC3ProcessJob.php' => $manifest['files']['tests/Support/BillingC3ProcessJob.php'] ?? null]);
        require_once $jobFile;
        BillingC3ProcessJob::assertLive();
        $deadline = BillingC3ProcessJob::deadline(BillingC3ProcessJob::HASH_SECONDS);
        self::assertPolicy($policy, $manifest, $manifestDigest);
        self::check(self::samePath(PHP_BINARY, $manifest['tools']['php']['path'])
            && self::samePath((string) ini_get('extension_dir'), $policy['roots']['php']['path'].'/ext'), 'billing_c3_php_boot_identity_mismatch');
        self::assertOwnedIni($parent ? null : (getenv('SILANSIA_RUNTIME_ROOT') ?: ''));
        self::assertLoadedNativeModules($policy['roots']['php']['path']);
        self::verifyProject($base, $manifest, $policy, $parent, $deadline);
        foreach ($parent ? self::HOST_ROOTS : ['php'] as $name) {
            self::assertTree($policy['roots'][$name]['path'], $policy['roots'][$name]['sha256'], $deadline);
        }
        BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_dependency_deadline_exceeded');
        BillingC3ProcessJob::assertLive();

        self::$verified = ['digest' => $digest, 'policy' => $policy, 'manifest' => $manifest];
    }

    public static function verified(): bool
    {
        return self::$verified !== null;
    }

    public static function policyDigest(): string
    {
        return self::proof()['digest'];
    }

    public static function verifyHostRoot(string $name): void
    {
        $entry = self::proof()['policy']['roots'][$name] ?? null;
        self::check(is_array($entry), 'billing_c3_dependency_policy_invalid');
        BillingC3ProcessJob::assertLive();
        $deadline = BillingC3ProcessJob::deadline(BillingC3ProcessJob::HASH_SECONDS);
        self::assertTree($entry['path'], $entry['sha256'], $deadline);
        BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_dependency_deadline_exceeded');
        BillingC3ProcessJob::assertLive();
    }

    public static function phpCommand(array $arguments, string $root): array
    {
        self::verifyHostRoot('php');
        self::verifyLaunchSources(['tests/Support/BillingC3Bootstrap.php', 'tests/Support/BillingC3ProcessJob.php',
            'tests/Support/billing-c3-supervisor.php', 'tests/Support/run-payment-r2-integration.php',
            'tests/Support/payment-r2-integration-server.php']);
        $proof = self::proof();
        $extensions = $proof['policy']['roots']['php']['path'].'/ext';

        return [$proof['manifest']['tools']['php']['path'], ...self::phpOptions($extensions, $root), ...$arguments];
    }

    public static function verifyNodeLaunch(bool $browser): void
    {
        self::verifyHostRoot('node');
        self::verifyLaunchSources(['tests/Browser/billing-c3-bootstrap.mjs',
            ...($browser ? ['tests/Browser/payment-r2-integration-browser.mjs'] : [
                'tests/Browser/payment-r2-integration-assets.mjs', 'tests/Browser/billing-c3-build.mjs',
            ])]);
    }

    private static function verifyLaunchSources(array $paths): void
    {
        $pins = self::proof()['manifest']['files'];
        $selected = [];
        foreach ($paths as $path) {
            $selected[$path] = $pins[$path] ?? null;
        }
        self::assertSourcePins(dirname(__DIR__, 2), $selected);
    }

    public static function phpOptions(string $extensions, ?string $root = null): array
    {
        $options = ['-n', '-d', 'extension_dir='.$extensions, '-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0',
            '-d', 'opcache.enable=0', '-d', 'include_path=.', '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0'];
        foreach (self::EXTENSIONS as $extension) {
            $options[] = '-d';
            $options[] = 'extension='.$extensions.'/php_'.$extension.'.dll';
        }
        foreach ($root === null ? ['log_errors' => '0'] : self::ownedIni($root) + ['log_errors' => '1'] as $key => $value) {
            $options[] = '-d';
            $options[] = $key.'='.$value;
        }

        return $options;
    }

    private static function ownedIni(string $root): array
    {
        return ['sys_temp_dir' => $root.'\\tmp', 'upload_tmp_dir' => $root.'\\uploads',
            'session.save_path' => $root.'\\storage\\framework\\sessions', 'error_log' => $root.'\\storage\\logs\\php-errors.log'];
    }

    private static function assertOwnedIni(?string $root): void
    {
        if ($root === null) {
            self::check(getenv('SILANSIA_RUNTIME_ROOT') === false && getenv('SILANSIA_RUNTIME_OWNER') === false,
                'billing_c3_php_parent_inherited_capability');

            return;
        }
        self::assertCanonical($root, true);
        foreach (self::ownedIni($root) as $key => $expected) {
            self::check(self::samePath((string) ini_get($key), $expected), 'billing_c3_php_owned_ini_mismatch');
        }
    }

    public static function assertExtensions(array $loaded): void
    {
        $actual = array_map('strtolower', $loaded);
        $expected = [...self::CORE_MODULES, ...self::EXTENSIONS];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        self::check($actual === $expected, 'billing_c3_php_extension_set_mismatch');
    }

    public static function assertLoadedNativeModules(string $phpRoot): int
    {
        $kernel = FFI::cdef(<<<'C'
            void *GetCurrentProcess(void);
            void *GetModuleHandleW(const uint16_t *name);
            uint32_t GetModuleFileNameW(void *module, uint16_t *buffer, uint32_t size);
            uint32_t GetSystemDirectoryW(uint16_t *buffer, uint32_t size);
            int K32EnumProcessModulesEx(void *process, void **modules, uint32_t capacity, uint32_t *needed, uint32_t flags);
            C, 'kernel32.dll');
        $buffer = $kernel->new('uint16_t[512]');
        $read = static function (int $length) use ($kernel, $buffer): string {
            self::check($length > 0 && $length < 512, 'billing_c3_php_native_module_unverified');
            $value = iconv('UTF-16LE', 'UTF-8', FFI::string($kernel->cast('char *', FFI::addr($buffer[0])), $length * 2));
            self::check(is_string($value), 'billing_c3_php_native_module_unverified');

            return $value;
        };
        $system = $read($kernel->GetSystemDirectoryW($buffer, 512));
        $modules = $kernel->new('void *[512]');
        $size = $kernel->new('uint32_t');
        self::check($kernel->K32EnumProcessModulesEx($kernel->GetCurrentProcess(), $modules, FFI::sizeof($modules), FFI::addr($size), 3) !== 0
            && $size->cdata > 0 && $size->cdata <= FFI::sizeof($modules) && $size->cdata % PHP_INT_SIZE === 0,
            'billing_c3_php_native_module_unverified');
        $count = intdiv($size->cdata, PHP_INT_SIZE);
        for ($index = 0; $index < $count; $index++) {
            $path = $read($kernel->GetModuleFileNameW($modules[$index], $buffer, 512));
            self::assertNativeModulePath($path, $phpRoot, $system);
        }
        foreach (['php8.dll' => $phpRoot.'/php8.dll', ...array_combine(
            array_map(static fn ($name) => 'php_'.$name.'.dll', self::EXTENSIONS),
            array_map(static fn ($name) => $phpRoot.'/ext/php_'.$name.'.dll', self::EXTENSIONS),
        )] as $name => $expected) {
            $bytes = iconv('UTF-8', 'UTF-16LE', $name."\0");
            $wide = $kernel->new('uint16_t['.intdiv(strlen($bytes), 2).']');
            FFI::memcpy($wide, $bytes, strlen($bytes));
            $handle = $kernel->GetModuleHandleW($wide);
            self::check($handle !== null && ! FFI::isNull($handle)
                && self::samePath($read($kernel->GetModuleFileNameW($handle, $buffer, 512)), $expected), 'billing_c3_php_native_module_unverified');
        }

        return $count;
    }

    public static function assertNativeModulePath(string $path, string $phpRoot, string $system): void
    {
        self::assertCanonical($path);
        $normalize = static fn ($value) => strtolower(str_replace('\\', '/', $value));
        $path = $normalize($path);
        self::check(str_starts_with($path, $normalize($phpRoot).'/') || str_starts_with($path, $normalize($system).'/'),
            'billing_c3_php_native_module_unverified');
    }

    public static function assertEnvironment(array $env): void
    {
        $blocked = ['PHPRC', 'PHP_INI_SCAN_DIR', 'NODE_OPTIONS', 'NODE_PATH', 'NODE_EXTRA_CA_CERTS', 'LD_PRELOAD', 'LD_LIBRARY_PATH'];
        foreach (array_keys($env) as $name) {
            self::check(! in_array(strtoupper((string) $name), $blocked, true), 'billing_c3_boot_environment_override');
        }
    }

    private static function proof(): array
    {
        self::check(self::$verified !== null, 'billing_c3_dependency_bootstrap_required');

        return self::$verified;
    }

    private static function assertPolicy(array $policy, array $manifest, string $manifestDigest): void
    {
        self::check(($policy['version'] ?? null) === 1 && ($policy['source_manifest_sha256'] ?? null) === $manifestDigest
            && is_array($policy['roots'] ?? null) && array_keys($policy['roots']) === self::HOST_ROOTS
            && is_array($manifest['files'] ?? null) && is_array($manifest['tools'] ?? null), 'billing_c3_dependency_policy_invalid');
        foreach ($policy['roots'] as $entry) {
            self::check(is_array($entry) && is_string($entry['path'] ?? null) && self::validHash($entry['sha256'] ?? null),
                'billing_c3_dependency_policy_invalid');
        }
        foreach (['php' => 'php.exe', 'node' => 'node.exe', 'chrome' => 'chrome.exe', 'postgres-bin' => 'postgres.exe'] as $key => $exe) {
            $tool = $key === 'postgres-bin' ? 'postgres' : $key;
            self::check(self::samePath($policy['roots'][$key]['path'].'/'.$exe, $manifest['tools'][$tool]['path'] ?? ''),
                'billing_c3_dependency_runtime_root_mismatch');
        }
        $postgres = dirname($policy['roots']['postgres-bin']['path']);
        foreach (['lib', 'share'] as $part) {
            self::check(self::samePath($postgres.'/'.$part, $policy['roots']['postgres-'.$part]['path']), 'billing_c3_dependency_runtime_root_mismatch');
        }
        foreach (['vendor_sha256', 'node_modules_sha256'] as $key) {
            self::check(self::validHash($policy[$key] ?? null), 'billing_c3_dependency_policy_invalid');
        }
    }

    private static function verifyProject(string $base, array $manifest, array $policy, bool $parent, int $deadline): void
    {
        self::check(array_keys($manifest['files']) === self::sourcePaths($base), 'billing_c3_source_set_mismatch');
        self::assertSourcePins($base, $manifest['files'], $deadline);
        self::assertTree($base.'/vendor', $policy['vendor_sha256'], $deadline);
        if ($parent) {
            for ($dir = dirname($base); ; $dir = dirname($dir)) {
                $candidate = $dir.'/node_modules';
                self::check(! file_exists($candidate) && ! is_link($candidate), 'billing_c3_dependency_ancestor_modules');
                if (dirname($dir) === $dir) {
                    break;
                }
            }
            self::assertTree($base.'/node_modules', $policy['node_modules_sha256'], $deadline);
        }
    }

    public static function sourcePaths(string $base): array
    {
        $paths = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.js', 'bootstrap/app.php', 'bootstrap/providers.php'];
        foreach (['app', 'config', 'database', 'routes', 'resources'] as $directory) {
            $root = $base.'/'.$directory;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                } else {
                    self::check($file->isDir() && ! $file->isLink(), 'billing_c3_source_set_mismatch');
                }
            }
        }
        foreach (['tests/Support/BillingC2*.php', 'tests/Support/BillingC3*.php', 'tests/Support/billing-c3-*.php',
            'tests/Support/PaymentR2Integration*.php', 'tests/Support/payment-r2-integration-*.php', 'tests/Browser/payment-r2-integration-*.mjs',
            'tests/Browser/billing-c3-*.mjs', 'tests/Unit/BillingC[23]*.php', 'tests/Frontend/billingC3*.test.mjs'] as $pattern) {
            foreach (glob($base.'/'.$pattern) as $path) {
                $paths[] = str_replace('\\', '/', substr($path, strlen($base) + 1));
            }
        }
        array_push($paths, 'tests/Support/billing-c3-launch.ps1', 'tests/Support/BillingIntegrityFixtures.php', 'tests/Support/FrameworkRuntimeIsolation.php',
            'tests/Support/PostgresTestGuard.php', 'tests/Support/isolated-runtime-prepend.php', 'tests/Support/run-payment-r2-integration.php');
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    public static function assertSourcePins(string $base, array $pins, ?int $deadline = null): void
    {
        self::check($pins !== [], 'billing_c3_dependency_source_pins_missing');
        foreach ($pins as $path => $expected) {
            self::checkHashDeadline($deadline);
            self::check(is_string($path) && preg_match('~\A[A-Za-z0-9_.@ -]+(?:/[A-Za-z0-9_.@ -]+)*\z~', $path) === 1
                && ! preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $path) && self::validHash($expected), 'billing_c3_dependency_source_pin_invalid');
            $file = $base.'/'.$path;
            self::assertCanonical($file);
            self::check(hash_equals($expected, hash_file('sha256', $file) ?: ''), 'billing_c3_dependency_source_pin_mismatch');
        }
        self::checkHashDeadline($deadline);
    }

    public static function readPinnedJson(string $path, string $digest): array
    {
        self::check(self::validHash($digest), 'billing_c3_dependency_policy_digest_required');
        self::assertCanonical($path);
        $bytes = file_get_contents($path, false, null, 0, 1_000_001);
        self::check(is_string($bytes) && strlen($bytes) <= 1_000_000 && hash_equals($digest, hash('sha256', $bytes)),
            'billing_c3_dependency_policy_digest_mismatch');
        $record = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        self::check(is_array($record), 'billing_c3_dependency_policy_invalid');

        return $record;
    }

    public static function assertTree(string $path, string $expected, ?int $deadline = null): void
    {
        self::check(self::validHash($expected) && hash_equals($expected, self::treeDigest($path, $deadline)), 'billing_c3_dependency_tree_mismatch');
    }

    /** Ordinal, depth-first, directory + file records. File bytes are hashed even when the version is unchanged. */
    public static function treeDigest(string $root, ?int $deadline = null): string
    {
        self::checkHashDeadline($deadline);
        self::assertCanonical($root, true);
        $hash = hash_init('sha256');
        $walk = static function (string $dir, string $prefix) use (&$walk, $hash, $deadline): void {
            $entries = [];
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
                self::checkHashDeadline($deadline);
                $entries[$entry->getFilename()] = $entry->getPathname();
            }
            ksort($entries, SORT_STRING);
            foreach ($entries as $name => $path) {
                self::checkHashDeadline($deadline);
                $relative = $prefix.$name;
                self::check(preg_match('/[\x00-\x1f\x7f-\xff]/', $relative) === 0, 'billing_c3_dependency_path_invalid');
                // Names are enumerated from a validated parent. A native reparse check on each
                // entry keeps the walk confined without repeatedly resolving every ancestor.
                $attributes = self::windowsAttributes($path);
                self::check($attributes === null ? ! is_link($path) : ($attributes & 0x400) === 0, 'billing_c3_dependency_path_reparse');
                $directory = $attributes === null ? is_dir($path) : ($attributes & 0x10) !== 0;
                if ($directory) {
                    hash_update($hash, "D\t".$relative."\n");
                    $walk($path, $relative.'/');
                } else {
                    $digest = hash_file('sha256', $path);
                    self::check(is_string($digest), 'billing_c3_dependency_file_unreadable');
                    hash_update($hash, "F\t".$relative."\t".$digest."\n");
                }
            }
        };
        $walk($root, '');
        self::checkHashDeadline($deadline);

        return hash_final($hash);
    }

    private static function checkHashDeadline(?int $deadline): void
    {
        self::check($deadline === null || hrtime(true) < $deadline, 'billing_c3_dependency_deadline_exceeded');
    }

    public static function assertCanonical(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        self::check(is_string($real) && ($directory ? is_dir($path) : is_file($path)), 'billing_c3_dependency_path_missing');
        self::check(self::samePath($real, $path), 'billing_c3_dependency_path_alias');
        for ($current = $path; ; $current = dirname($current)) {
            self::check(! is_link($current) && ((self::windowsAttributes($current) ?? 0) & 0x400) === 0, 'billing_c3_dependency_path_reparse');
            if (dirname($current) === $current || strlen($current) <= 3) {
                break;
            }
        }
    }

    private static function windowsAttributes(string $path): ?int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        static $kernel;
        $kernel ??= FFI::cdef('uint32_t GetFileAttributesW(const uint16_t *path);', 'kernel32.dll');
        $bytes = iconv('UTF-8', 'UTF-16LE', str_replace('/', '\\', $path)."\0");
        self::check(is_string($bytes), 'billing_c3_dependency_path_invalid');
        $buffer = $kernel->new('uint16_t['.intdiv(strlen($bytes), 2).']');
        FFI::memcpy($buffer, $bytes, strlen($bytes));
        $attributes = $kernel->GetFileAttributesW($buffer);
        self::check($attributes !== 0xffffffff, 'billing_c3_dependency_path_unverified');

        return $attributes;
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', $left);
        $right = str_replace('\\', '/', $right);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private static function validHash(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/', $digest) === 1;
    }

    private static function check(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new LogicException($code);
        }
    }
}
