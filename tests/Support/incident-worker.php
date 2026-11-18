<?php

use App\Exceptions\IncidentConflictException;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\FallIncidentAction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AiIngestionService;
use App\Services\IncidentService;
use App\Services\ResidentOperationsService;
use App\Support\AiGateway\EventPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    $actor = User::findOrFail($data['actor_id']);
    $guard->assertSafe($app, DB::connection());
    echo json_encode(['ready' => true, 'pid' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR).PHP_EOL;
    fflush(STDOUT);
    if ($data['hold_at_journal'] ?? false) {
        FallIncidentAction::creating(function () {
            echo json_encode(['locked' => true]).PHP_EOL;
            fflush(STDOUT);
            if (trim(fgets(STDIN)) !== 'release') {
                throw new LogicException('barrier_invalid');
            }
        });
    }
    $result = match ($data['action']) {
        'gateway_duplicate' => app(AiIngestionService::class)->ingest(new EventPayload($data['payload']), $data['source']),
        'deactivate' => app(AccountService::class)->setActive($actor, User::findOrFail($data['target_id']), false),
        'close_stay' => app(ResidentOperationsService::class)->endStay($actor, Elderly::findOrFail($data['elderly_id']), ElderlyStay::findOrFail($data['stay_id'])),
        default => app(IncidentService::class)->act($actor, $data['incident_id'], $data['action'], $data['input']),
    };
    echo json_encode(['ok' => true, 'duplicate' => $result['duplicate'] ?? false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (IncidentConflictException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'conflict']).PHP_EOL;
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'validation', 'fields' => array_keys($exception->errors())]).PHP_EOL;
} catch (AuthorizationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'authorization']).PHP_EOL;
} catch (Throwable $exception) {
    if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403) {
        echo json_encode(['ok' => false, 'kind' => 'authorization']).PHP_EOL;
    } else {
        echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => get_class($exception)]).PHP_EOL;
        exit(1);
    }
}
