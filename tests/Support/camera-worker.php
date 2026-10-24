<?php

// Fixture/worker konkurensi; bukan ingestion atau command operasional aplikasi.
use App\Models\Camera;
use App\Models\Room;
use App\Models\User;
use App\Services\CameraService;
use App\Services\RoomService;
use Illuminate\Contracts\Console\Kernel;
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
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    foreach (['actor_id', 'room_id', 'class_id'] as $field) {
        if (! Str::isUuid($data[$field] ?? '')) {
            throw new LogicException('payload_invalid');
        }
    }
    $actor = User::findOrFail($data['actor_id']);
    $camera = isset($data['camera_id']) ? Camera::findOrFail($data['camera_id']) : null;
    $guard->assertSafe($app, DB::connection());
    fwrite(STDOUT, json_encode(['ready' => true, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
    $service = app(CameraService::class);
    $result = match ($data['action']) {
        'create' => $service->create($actor, ['room_id' => $data['room_id'], 'name' => 'Kamera race', 'source_type' => 'webcam']),
        'edit', 'reassign' => $service->update($actor, $camera, ['room_id' => $data['room_id'], 'current_room_id' => $data['current_room_id'],
            'name' => $data['name'], 'source_type' => 'webcam', 'source_action' => 'keep', 'version' => $data['version'], 'confirm_reassignment' => true]),
        'deactivate_room' => app(RoomService::class)->setActive($actor, Room::findOrFail($data['room_id']), false),
        'record_detection' => $camera->aiDetections()->create(['external_event_id' => 'fixture-race-'.$camera->id,
            'event_type' => 'fall', 'occurred_at' => now(), 'received_at' => now()]),
        default => throw new LogicException('action_invalid'),
    };
    echo json_encode(['ok' => true, 'id' => $result?->id], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'validation', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => get_class($exception)], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
