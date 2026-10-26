<?php

namespace Tests\Feature\AiGateway;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\FallIncident;
use App\Models\GaitObservation;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\CameraOperatingPeriodService;
use App\Services\CameraService;
use App\Support\CameraVersion;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestGuard;
use Tests\TestCase;

class AiConcurrencyTest extends TestCase
{
    private bool $verified = false;

    private array $classIds = [];

    private array $roomIds = [];

    private array $userIds = [];

    private array $cameraIds = [];

    private array $elderlyIds = [];

    private array $cacheKeys = [];

    private array $processes = [];

    private function guard(): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'silansia_testing') {
            $this->markTestSkipped('Memerlukan runner PostgreSQL terisolasi.');
        }
        $this->guard();
        $this->verified = true;
        $this->assertSame(0, DB::transactionLevel());
        $before = ServiceClass::pluck('id')->all();
        $this->seed(ServiceClassSeeder::class);
        $this->classIds = ServiceClass::whereNotIn('id', $before)->pluck('id')->all();
        $this->assertCount(3, $this->classIds);
    }

    public static function races(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate', 'conflict', 'nonce', 'move_first', 'ingest_first',
            'deactivate', 'reactivate', 'reconfigure_first', 'ingest_reconfigure', 'start_stay', 'end_stay']);
    }

    #[DataProvider('races')]
    public function test_processes_preserve_deduplication_and_historical_relationships(string $case): void
    {
        $this->guard();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->userIds = [$admin->id, $otherAdmin->id];
        $vip = in_array($case, ['start_stay', 'end_stay'], true);
        $class = ServiceClass::where('code', $vip ? 'vip' : 'class_2')->sole();
        $rooms = [];
        for ($i = 0; $i < 2; $i++) {
            $rooms[] = $class->rooms()->create(['code' => 'AI-'.$i.'-'.substr($admin->id, 0, 18), 'name' => 'Kamar fixture gateway', 'capacity' => $vip ? 1 : 4]);
            $this->roomIds[] = $rooms[$i]->id;
        }
        $camera = Camera::create(['room_id' => $rooms[0]->id, 'name' => 'Kamera fixture gateway', 'source_type' => 'webcam', 'is_active' => false])->fresh();
        $this->cameraIds[] = $camera->id;
        app(CameraService::class)->setActive($admin, $camera, CameraVersion::of($camera), true);
        $camera->refresh();
        $period = $camera->operatingPeriods()->sole();
        $time = CameraOperatingPeriodService::serverTime()->subMinute();
        $period->update(['opened_at' => $time->subHour()]);
        $period->refresh();
        $inactive = in_array($case, ['move_first', 'ingest_first', 'reactivate', 'reconfigure_first', 'ingest_reconfigure'], true);
        if ($inactive) {
            app(CameraService::class)->setActive($admin, $camera, CameraVersion::of($camera), false);
            $camera->refresh();
        }
        $resident = $enrollment = $stay = null;
        if ($vip) {
            $family = User::factory()->create(['role' => 'keluarga', 'is_active' => true]);
            $this->userIds[] = $family->id;
            $resident = $family->elderlies()->create(['name' => 'Lansia fixture gateway', 'birth_date' => '1950-01-01']);
            $this->elderlyIds[] = $resident->id;
            $enrollment = ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $class->id,
                'monthly_price' => '123.45', 'started_at' => $time->subHours(2)->startOfSecond()]);
            if ($case === 'end_stay') {
                $stay = ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $rooms[0]->id, 'started_at' => $time->subHour()->startOfSecond()]);
            }
        }
        $sourceId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        $secondNonce = $case === 'nonce' ? $nonce : bin2hex(random_bytes(32));
        $ip = '198.51.100.'.(1 + hexdec(substr($admin->id, -2)) % 250);
        $type = $vip ? 'gait' : 'fall';
        $payload = ['contract_version' => '1', 'external_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'camera_id' => $camera->id, 'camera_period_id' => $period->id, 'occurred_at' => $time->format('Y-m-d\TH:i:s.u\Z'),
            'confidence' => '0.85000', ...($vip ? ['category' => 'walking_normal'] : [])];
        $otherAction = match ($case) {
            'move_first', 'ingest_first' => 'move', 'deactivate' => 'deactivate', 'reactivate' => 'reactivate',
            'reconfigure_first', 'ingest_reconfigure' => 'reconfigure', 'start_stay' => 'start_stay', 'end_stay' => 'end_stay',
            default => 'ingest'
        };
        $base = ['camera_id' => $camera->id, 'source_id' => $sourceId, 'key_id' => (string) Str::uuid(), 'ip' => $ip, 'payload' => $payload,
            'current_room_id' => $rooms[0]->id, 'target_room_id' => $rooms[1]->id, 'version' => CameraVersion::of($camera),
            'elderly_id' => $resident?->id, 'enrollment_id' => $enrollment?->id, 'stay_id' => $stay?->id];
        $firstIsOperation = in_array($case, ['move_first', 'reconfigure_first'], true);
        $data = [
            [...$base, 'actor_id' => $admin->id, 'action' => $firstIsOperation ? $otherAction : 'ingest', 'nonce' => $nonce],
            [...$base, 'actor_id' => $otherAdmin->id, 'action' => $firstIsOperation ? 'ingest' : $otherAction, 'nonce' => $secondNonce],
        ];
        if ($case === 'conflict') {
            $data[1]['payload']['confidence'] = '0.90000';
        }
        $this->cacheKeys = ['ai-gateway:ip:'.hash('sha256', $ip), 'ai-gateway:source:'.$sourceId.':'.$type];
        foreach ([$nonce, $secondNonce] as $n) {
            $this->cacheKeys[] = 'ai-gateway:nonce:'.hash('sha256', $sourceId.':'.$n);
            $this->cacheKeys[] = 'ai-gateway:nonce:'.hash('sha256', $sourceId.':'.hash('sha256', $n.':retry'));
        }
        $this->guard();
        DB::beginTransaction();
        try {
            ServiceClass::whereKey($class->id)->lockForUpdate()->sole();
            $pids = [];
            foreach ($data as $index => $item) {
                $process = new Process([PHP_BINARY, 'tests/Support/ai-gateway-worker.php', json_encode($item, JSON_THROW_ON_ERROR)], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
                $process->setTimeout(40);
                $process->start();
                $this->processes[] = $process;
                $pids[] = $this->readyPid($process);
                if ($index === 0) {
                    $this->assertWaiting($pids, 1);
                }
            }
            $this->assertNotSame($pids[0], $pids[1]);
            if ($case === 'nonce') {
                $this->processes[1]->wait();
                $this->assertTrue($this->processes[1]->isSuccessful());
            }
            $this->assertWaiting($pids, $case === 'nonce' ? 1 : 2);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Error infrastruktur tidak boleh dihitung sebagai penolakan bisnis.');
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $results[] = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
        }
        $http = array_values(array_filter($results, fn ($r) => $r['kind'] === 'http'));
        $statuses = array_column($http, 'status');
        sort($statuses);
        if (in_array($case, ['duplicate', 'conflict', 'nonce'], true)) {
            $this->assertSame(match ($case) {
                'duplicate' => [200, 201], 'conflict' => [201, 409], 'nonce' => [201, 401]
            }, $statuses);
        } else {
            $this->assertSame([201], $statuses);
            $operation = collect($results)->first(fn ($r) => $r['kind'] !== 'http');
            if (in_array($case, ['ingest_first', 'ingest_reconfigure'], true)) {
                $this->assertSame('validation', $operation['kind']);
                $this->assertSame(422, $operation['status']);
                $this->assertContains($case === 'ingest_first' ? 'room_id' : 'source_type', $operation['fields']);
            } else {
                $this->assertSame(['kind' => 'operation', 'status' => 200], $operation);
            }
        }
        $event = AiDetection::where('camera_id', $camera->id)->sole();
        $this->assertSame($period->id, $event->camera_period_id);
        $this->assertSame($rooms[0]->id, $event->cameraPeriod->room_id);
        $this->assertSame($type === 'fall' ? 1 : 0, $event->fallIncident()->count());
        $this->assertSame($type === 'gait' ? 1 : 0, $event->gaitObservation()->count());
        if ($case === 'move_first') {
            $this->assertSame($rooms[1]->id, $camera->fresh()->room_id);
        }
        if ($case === 'reactivate') {
            $this->assertCount(2, $camera->operatingPeriods()->get());
            $this->assertNotSame($period->id, $camera->operatingPeriods()->whereNull('closed_at')->sole()->id);
        }
        if ($case === 'reconfigure_first') {
            $this->assertSame('rtsp', $camera->fresh()->source_type);
            $this->assertSame('webcam', $period->fresh()->source_type);
        }
        if ($case === 'start_stay') {
            $this->assertNull($event->gaitObservation->elderly_id);
            $this->assertSame(1, $resident->activeStay()->count());
        }
        if ($case === 'end_stay') {
            $this->assertSame($resident->id, $event->gaitObservation->elderly_id);
            $this->assertNotNull($stay->fresh()->ended_at);
        }
    }

    private function readyPid(Process $process): int
    {
        $deadline = microtime(true) + 15;
        do {
            $line = strtok($process->getOutput(), "\n");
            if ($line && ($ready = json_decode($line, true)) && ($ready['ready'] ?? false)) {
                return $ready['pid'];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker gagal memverifikasi bootstrap dan identitas database.');
    }

    private function assertWaiting(array $pids, int $expected): void
    {
        $deadline = microtime(true) + 15;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
            if ($waiting === $expected) {
                $this->assertSame($expected, $waiting);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Interleaving tidak terbukti melalui lock PostgreSQL.');
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if ($this->verified) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->guard();
            $eventIds = AiDetection::whereIn('camera_id', $this->cameraIds)->pluck('id')->all();
            foreach ([[FallIncident::class, 'ai_detection_id', $eventIds], [GaitObservation::class, 'ai_detection_id', $eventIds],
                [AiDetection::class, 'id', $eventIds], [CameraOperatingPeriod::class, 'camera_id', $this->cameraIds],
                [Camera::class, 'id', $this->cameraIds], [ElderlyStay::class, 'elderly_id', $this->elderlyIds],
                [ServiceEnrollment::class, 'elderly_id', $this->elderlyIds], [Elderly::class, 'id', $this->elderlyIds],
                [Room::class, 'id', $this->roomIds], [User::class, 'id', $this->userIds], [ServiceClass::class, 'id', $this->classIds]] as [$model, $field, $ids]) {
                $this->guard();
                $model::whereIn($field, $ids)->delete();
            }
            foreach (array_unique($this->cacheKeys) as $key) {
                foreach ([$key, $key.':timer'] as $cacheKey) {
                    $this->guard();
                    Cache::store('database')->forget($cacheKey);
                }
            }
        }
        parent::tearDown();
    }
}
