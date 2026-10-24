<?php

// Worker hanya menerima DB acak milik runner; tidak boleh dijalankan sebagai command aplikasi.
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\ResidentOperationsService;
use App\Services\ResidentService;
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
    foreach (['actor_id', 'elderly_id', 'class_id', 'room_id'] as $field) {
        if (! Str::isUuid($data[$field] ?? '')) {
            throw new LogicException('payload_invalid');
        }
    }
    $actor = User::findOrFail($data['actor_id']);
    $elderly = Elderly::findOrFail($data['elderly_id']);
    $service = app(ResidentOperationsService::class);
    $guard->assertSafe($app, DB::connection());
    fwrite(STDOUT, json_encode(['ready' => true, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
    $result = match ($data['action']) {
        'start' => $service->startStay($actor, $elderly, $data['room_id'], $data['enrollment_id']),
        'enroll' => $service->enroll($actor, $elderly, $data['class_id']),
        'end_enrollment' => $service->endEnrollment($actor, $elderly, ServiceEnrollment::findOrFail($data['enrollment_id'])),
        'end_stay' => $service->endStay($actor, $elderly, ElderlyStay::findOrFail($data['stay_id'])),
        'deactivate' => app(ResidentService::class)->setActive($actor, $elderly, false),
        'reduce_capacity' => app(RoomService::class)->update($actor, $room = Room::findOrFail($data['room_id']), [
            'code' => $room->code, 'name' => $room->name, 'service_class_id' => $room->service_class_id,
            'current_service_class_id' => $room->service_class_id, 'capacity' => 1]),
        default => throw new LogicException('action_invalid'),
    };
    echo json_encode(['ok' => true, 'id' => $result?->id], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'validation', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => get_class($exception)], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
