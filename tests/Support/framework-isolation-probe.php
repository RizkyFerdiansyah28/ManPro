<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;

$stage = 'environment';
try {
    Runtime::environment();
    $stage = 'configure';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(Runtime::root().'/environment');
    $app->loadEnvironmentFrom('absent.env');
    $case = $argv[1] ?? '';
    if ($case !== '') {
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($case): void {
            $base = dirname(__DIR__, 2);
            $key = match ($case) {
                'view' => 'view.compiled', 'cache' => 'cache.stores.file.path', 'session' => 'session.files',
                'logger_fallback', 'logger_file' => 'logging.default', default => throw new LogicException('runtime_probe_case'),
            };
            $app['config']->set($key, str_starts_with($case, 'logger') ? ($case === 'logger_file' ? 'single' : 'missing_isolation_channel') : $base.'/storage');
        });
    }
    Runtime::attach($app);
    $stage = 'bootstrap';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'resolved';
    $record = Runtime::resolved($app);
    $connections = array_keys($app->make('db')->getConnections());
    if ($connections !== []) {
        throw new LogicException('runtime_probe_database_opened');
    }
    echo json_encode(['ok' => true, 'resolved' => $record, 'database_connection_opened' => $connections !== [], 'fixture_created' => false], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'reason' => $error instanceof LogicException ? $error->getMessage() : 'runtime_probe_failure', 'stage' => $stage, 'exception_class' => get_class($error),
        'diagnostic' => str_replace(dirname(__DIR__, 2), '[workspace]', $error->getMessage())]);
    exit(78);
}
