<?php

use App\Models\Camera;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CameraService;
use App\Services\FallSafetyNotificationService;
use App\Services\IncidentService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FrameworkRuntimeIsolation;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    FrameworkRuntimeIsolation::attach($app);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $guard->assertSafe($app, DB::connection());
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    config(['queue.connections.database.connection' => 'silansia_testing']);
    if ($data['action'] === 'queue_once') {
        $guard->assertSafe($app, Queue::connection('database')->getDatabase());
        $guard->assertSafe($app, DB::connection(config('queue.failed.database')));
    }
    echo json_encode(['ready' => true, 'pid' => DB::scalar('SELECT pg_backend_pid()')]).PHP_EOL;
    fflush(STDOUT);
    if (($data['start_barrier'] ?? false) && trim(fgets(STDIN)) !== 'go') {
        throw new LogicException('barrier_invalid');
    }
    $guard->assertSafe($app, DB::connection());
    $result = match ($data['action']) {
        'deliver' => app(FallSafetyNotificationService::class)->deliver($data['incident_id']),
        'read_all' => app(NotificationService::class)->read(User::findOrFail($data['actor_id'])),
        'read' => app(NotificationService::class)->read(User::findOrFail($data['actor_id']), $data['notification_id']),
        'deactivate' => app(AccountService::class)->setActive(User::findOrFail($data['actor_id']), User::findOrFail($data['target_id']), false),
        'activate' => app(AccountService::class)->setActive(User::findOrFail($data['actor_id']), User::findOrFail($data['target_id']), true),
        'camera_deactivate' => app(CameraService::class)->setActive(User::findOrFail($data['actor_id']), Camera::findOrFail($data['camera_id']), $data['version'], false),
        'acknowledge' => app(IncidentService::class)->act(User::findOrFail($data['actor_id']), $data['incident_id'], 'acknowledge', $data['input']),
        'reconcile' => app(FallSafetyNotificationService::class)->reconcile(),
        'queue_once' => $kernel->call('queue:work', ['connection' => 'database', '--queue' => 'notifications',
            '--once' => true, '--stop-when-empty' => true, '--tries' => 3, '--timeout' => 20, '--no-interaction' => true]),
        default => throw new LogicException('action_invalid'),
    };
    echo json_encode(['ok' => true, 'result' => is_int($result) ? $result : null]).PHP_EOL;
} catch (AuthorizationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'authorization']).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => get_class($exception)]).PHP_EOL;
    exit(1);
}
