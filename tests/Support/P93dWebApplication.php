<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use LogicException;
use Monolog\Handler\NullHandler;

final class P93dWebApplication
{
    public static function boot(): Application
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertCacheIsolated();
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing'
            || getenv('SILANSIA_P93D_VERIFY') !== '1') {
            throw new LogicException('p93d_environment_rejected');
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        FrameworkRuntimeIsolation::attach($app);
        // No development .env is loaded by the real HTTP server or its read-only control.
        $app->useEnvironmentPath(dirname($guard->identity['cache']));
        $app->loadEnvironmentFrom('p93d-environment-does-not-exist');
        $app->make(Kernel::class)->bootstrap();
        $guard->assertSafe($app, DB::connection());
        config(['logging.default' => 'p93d_null', 'logging.channels.p93d_null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
        $app->make(Vite::class)->useHotFile(dirname($guard->identity['cache']).'/p93d-no-hot-file');
        if (FrameworkRuntimeIsolation::active()) {
            FrameworkRuntimeIsolation::resolved($app);
        }

        return $app;
    }
}
