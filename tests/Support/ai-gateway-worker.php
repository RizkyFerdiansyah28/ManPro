<?php

// Worker fixture: tidak dapat berjalan tanpa identitas database acak milik runner.
use App\Models\Camera;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\User;
use App\Services\CameraService;
use App\Services\ResidentOperationsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    $data = json_decode($argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR);
    foreach (['camera_id', 'actor_id', 'source_id', 'key_id'] as $field) {
        if (! Str::isUuid($data[$field] ?? '')) {
            throw new LogicException('payload_invalid');
        }
    }
    config(['ai_gateway.enabled' => true, 'ai_gateway.audience' => 'silansia-race-test',
        'ai_gateway.sources_json' => json_encode([['id' => $data['source_id'], 'enabled' => true, 'camera_ids' => [$data['camera_id']],
            'event_types' => ['fall', 'gait'], 'keys' => [['id' => $data['key_id'], 'enabled' => true, 'secret' => 'test-only-race-key-00000000000000000000000000000000']]]], JSON_THROW_ON_ERROR),
        'ai_gateway.rates' => ['ip' => 5000, 'fall' => 5000, 'gait' => 5000]]);
    $actor = User::findOrFail($data['actor_id']);
    $camera = Camera::findOrFail($data['camera_id']);
    $guard->assertSafe($app, DB::connection());
    fwrite(STDOUT, json_encode(['ready' => true, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
    if ($data['action'] === 'ingest') {
        $statuses = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $guard->assertSafe($app, DB::connection());
            $body = json_encode($data['payload'], JSON_THROW_ON_ERROR);
            $path = '/api/ai/v1/'.($data['payload']['event_type'] === 'gait' ? 'gait-observations' : 'fall-detections');
            $timestamp = (string) time();
            $nonce = $attempt === 0 ? $data['nonce'] : hash('sha256', $data['nonce'].':retry');
            $message = implode("\n", ['SILANSIA-AI-V1', 'silansia-race-test', 'POST', $path, $data['source_id'], $data['key_id'], $timestamp, $nonce, hash('sha256', $body)]);
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $data['ip'],
                'HTTP_X_SILANSIA_SOURCE' => $data['source_id'], 'HTTP_X_SILANSIA_KEY' => $data['key_id'], 'HTTP_X_SILANSIA_TIMESTAMP' => $timestamp,
                'HTTP_X_SILANSIA_NONCE' => $nonce, 'HTTP_AUTHORIZATION' => 'Silansia-HMAC-SHA256 '.hash_hmac('sha256', $message, 'test-only-race-key-00000000000000000000000000000000')];
            $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Request::create($path, 'POST', [], [], [], $server, $body);
            $response = $http->handle($request);
            $http->terminate($request, $response);
            $status = $response->getStatusCode();
            $result = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);
            $statuses[] = $status;
            if ($status >= 500) {
                throw new RuntimeException('http_infrastructure_failure');
            }
            if ($status !== 409 || ($result['error']['code'] ?? null) !== 'camera_state_changed') {
                break;
            }
        }
        echo json_encode(['kind' => 'http', 'status' => $status, 'code' => $result['error']['code'] ?? null,
            'duplicate' => $result['duplicate'] ?? null, 'attempts' => $statuses], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        $guard->assertSafe($app, DB::connection());
        match ($data['action']) {
            'move' => app(CameraService::class)->update($actor, $camera, ['current_room_id' => $data['current_room_id'], 'room_id' => $data['target_room_id'],
                'name' => $camera->name, 'source_type' => $camera->source_type, 'source_action' => 'keep', 'confirm_reassignment' => true, 'version' => $data['version']]),
            'deactivate' => app(CameraService::class)->setActive($actor, $camera, $data['version'], false),
            'reactivate' => app(CameraService::class)->setActive($actor, $camera, $data['version'], true),
            'reconfigure' => app(CameraService::class)->update($actor, $camera, ['current_room_id' => $data['current_room_id'], 'room_id' => $data['current_room_id'],
                'name' => $camera->name, 'source_type' => 'rtsp', 'source_action' => 'clear', 'version' => $data['version']]),
            'start_stay' => app(ResidentOperationsService::class)->startStay($actor, Elderly::findOrFail($data['elderly_id']), $data['current_room_id'], $data['enrollment_id']),
            'end_stay' => app(ResidentOperationsService::class)->endStay($actor, Elderly::findOrFail($data['elderly_id']), ElderlyStay::findOrFail($data['stay_id'])),
            default => throw new LogicException('action_invalid'),
        };
        echo json_encode(['kind' => 'operation', 'status' => 200], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (ValidationException $error) {
    echo json_encode(['kind' => 'validation', 'status' => 422, 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['kind' => 'infrastructure', 'exception' => $error::class], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
