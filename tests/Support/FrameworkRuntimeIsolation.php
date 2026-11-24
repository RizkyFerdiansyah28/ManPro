<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use LogicException;
use Monolog\Handler\NullHandler;

/** Test-only, independent of application billing/DB guards. Never repairs a path. */
final class FrameworkRuntimeIsolation
{
    public static function active(): bool
    {
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '{}', true);

        return getenv('SILANSIA_P94E1_VERIFY') !== false || getenv('SILANSIA_RUNTIME_ROOT') !== false
            || isset($identity['runtime_root']);
    }

    public static function root(): string
    {
        $root = getenv('SILANSIA_RUNTIME_ROOT');
        if (getenv('SILANSIA_P94E1_VERIFY') !== '1' || ! is_string($root) || $root === '') {
            throw new LogicException('runtime_context_missing');
        }
        if (! preg_match('/\Asilansia-p94e1-[a-f0-9]{16}\z/', basename($root)) || ! is_dir($root)) {
            throw new LogicException('runtime_root_invalid');
        }
        self::canonical($root);
        $marker = $root.DIRECTORY_SEPARATOR.'owner.json';
        self::canonical($marker);
        $owner = json_decode(file_get_contents($marker), true);
        $token = getenv('SILANSIA_RUNTIME_OWNER');
        if (! is_array($owner) || ($owner['root'] ?? null) !== $root || ! is_string($token)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $token)
            || ! hash_equals($owner['token'] ?? '', $token)) {
            throw new LogicException('runtime_owner_invalid');
        }

        return $root;
    }

    public static function canonical(string $path): void
    {
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) {
                throw new LogicException('runtime_path_link');
            }
            if (file_exists($cursor)) {
                $actual = realpath($cursor);
                if ($actual === false || strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $cursor)) !== 0) {
                    throw new LogicException('runtime_path_alias');
                }
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }

    public static function path(string $path, bool $directory = false): string
    {
        $root = self::root();
        $normalize = static fn ($value) => strtolower(str_replace('\\', '/', $value));
        if (! str_starts_with($normalize($path), $normalize($root).'/')) {
            throw new LogicException('runtime_path_escape');
        }
        self::canonical($path);
        if ($directory && (! is_dir($path) || ! is_writable($path))) {
            throw new LogicException('runtime_directory_unwritable');
        }
        if (! $directory && ! is_writable(is_dir($path) ? $path : dirname($path))) {
            throw new LogicException('runtime_path_unwritable');
        }

        return $path;
    }

    public static function environment(): void
    {
        $root = self::root();
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '{}', true);
        if (isset($identity['runtime_root']) && $identity['runtime_root'] !== $root) {
            throw new LogicException('runtime_identity_mismatch');
        }
        foreach (['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'TMP', 'TEMP', 'TMPDIR'] as $key) {
            $value = getenv($key);
            if (! is_string($value) || $value === '') {
                throw new LogicException('runtime_environment_missing');
            }
            self::path($value, true);
        }
        if (getenv('LOG_CHANNEL') !== '"null"') {
            throw new LogicException('runtime_logger_environment');
        }
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            $value = getenv($key);
            if (! is_string($value) || $value === '') {
                throw new LogicException('runtime_environment_missing');
            }
            self::path($value);
        }
        $preverifiedC3 = class_exists(BillingC3Bootstrap::class, false) && BillingC3Bootstrap::verified();
        $expectedPrepend = $preverifiedC3 ? '' : str_replace('\\', '/', __DIR__.'/isolated-runtime-prepend.php');
        if (ini_get('auto_prepend_file') !== $expectedPrepend) {
            throw new LogicException('runtime_prepend_missing');
        }
        self::path(sys_get_temp_dir(), true);
        self::path(ini_get('upload_tmp_dir'), true);
        self::path(ini_get('session.save_path'), true);
        self::path(ini_get('error_log'));
    }

    public static function attach(Application $app): void
    {
        self::environment();
        $app->useStoragePath(getenv('LARAVEL_STORAGE_PATH'));
        // Laravel's default absolute cache prefixes omit Windows drive letters.
        // All env destinations have already passed canonical owned-root validation.
        if (preg_match('/\A[A-Za-z]:/', self::root(), $drive)) {
            $app->addAbsoluteCachePathPrefix($drive[0]);
        }
        foreach (['getCachedConfigPath', 'getCachedServicesPath', 'getCachedPackagesPath', 'getCachedRoutesPath', 'getCachedEventsPath'] as $method) {
            self::path($app->{$method}());
        }
        // Runs before HandleExceptions/RegisterProviders can resolve an emergency logger.
        $app->afterBootstrapping(LoadConfiguration::class, fn (Application $app) => self::resolved($app));
    }

    public static function resolved(Application $app): array
    {
        self::environment();
        self::path($app->storagePath(), true);
        foreach (['view.compiled', 'cache.stores.file.path', 'cache.stores.file.lock_path', 'session.files', 'filesystems.disks.local.root', 'filesystems.disks.public.root'] as $key) {
            $value = $app['config']->get($key);
            if (! is_string($value)) {
                throw new LogicException('runtime_configuration_missing');
            }
            self::path($value, true);
        }
        foreach (['single', 'daily', 'emergency'] as $channel) {
            $path = $app['config']->get('logging.channels.'.$channel.'.path', $app->storagePath('logs/laravel.log'));
            self::path($path);
        }
        $channel = $app['config']->get('logging.default');
        if (! in_array($channel, ['null', 'p93d_null'], true)
            || $app['config']->get('logging.channels.'.$channel.'.driver') !== 'monolog'
            || $app['config']->get('logging.channels.'.$channel.'.handler') !== NullHandler::class) {
            throw new LogicException('runtime_logger_profile');
        }
        $logger = $app->make('log')->channel($channel)->getLogger();
        $handlers = $logger->getHandlers();
        if (count($handlers) !== 1 || get_class($handlers[0]) !== NullHandler::class) {
            throw new LogicException('runtime_logger_handler');
        }
        $record = ['pid' => getmypid(), 'environment' => $app->environment(), 'root' => self::root(),
            'storage' => $app->storagePath(), 'compiled_views' => $app['config']->get('view.compiled'),
            'file_cache' => $app['config']->get('cache.stores.file.path'), 'file_sessions' => $app['config']->get('session.files'),
            'temp' => sys_get_temp_dir(), 'uploads' => ini_get('upload_tmp_dir'), 'php_error_log' => ini_get('error_log'),
            'logger_channel' => $channel, 'logger_handlers' => [get_class($handlers[0])], 'logger_destination' => null];
        file_put_contents(self::root().'/resolved.jsonl', json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

        return $record;
    }

    public static function protectedSnapshot(): array
    {
        clearstatcache();
        $base = dirname(__DIR__, 2);
        $result = [];
        $visit = function (string $path) use (&$visit, &$result, $base): void {
            $key = str_replace('\\', '/', substr($path, strlen($base) + 1));
            if (is_link($path)) {
                $result[$key] = ['type' => 'link'];
            } elseif (is_dir($path)) {
                $result[$key] = ['type' => 'directory'];
                foreach (scandir($path) as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $visit($path.DIRECTORY_SEPARATOR.$name);
                    }
                }
            } elseif (is_file($path)) {
                $result[$key] = ['type' => 'file', 'size' => filesize($path), 'mtime' => filemtime($path), 'sha256' => hash_file('sha256', $path)];
            }
        };
        foreach (['storage', 'bootstrap/cache', 'public/build', 'public/hot'] as $relative) {
            $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (file_exists($path) || is_link($path)) {
                $visit($path);
            }
        }
        ksort($result);

        return $result;
    }

    public static function assertProtected(): void
    {
        $root = self::root();
        $expected = json_decode(file_get_contents($root.'/protected.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($expected !== self::protectedSnapshot()) {
            throw new LogicException('runtime_protected_storage_changed');
        }
    }
}
