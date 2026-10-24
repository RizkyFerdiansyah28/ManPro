<?php

namespace Tests\Feature\Cameras;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Support\CameraVersion;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestGuard;
use Tests\TestCase;

class CameraConcurrencyTest extends TestCase
{
    private bool $verified = false;

    private array $users = [];

    private array $rooms = [];

    private array $classes = [];

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
        $this->classes = ServiceClass::whereNotIn('id', $before)->pluck('id')->all();
    }

    public static function races(): array
    {
        return [['create'], ['edit'], ['room_deactivation'], ['reassign'], ['history']];
    }

    #[DataProvider('races')]
    public function test_real_processes_preserve_camera_and_room_consistency(string $case): void
    {
        $this->guard();
        $admins = [User::factory()->create(['role' => 'admin', 'is_active' => true]), User::factory()->create(['role' => 'admin', 'is_active' => true])];
        $this->users = array_map(fn ($user) => $user->id, $admins);
        $this->assertNotSame($admins[0]->id, $admins[1]->id);
        $class = ServiceClass::where('code', 'class_2')->sole();
        $this->assertContains($class->id, $this->classes);
        $roomModels = [];
        for ($i = 0; $i < 3; $i++) {
            $room = $class->rooms()->create(['code' => 'CR-'.$i.'-'.substr($admins[0]->id, 0, 16), 'name' => 'Fixture kamar kamera', 'capacity' => 4]);
            $this->rooms[] = $room->id;
            $roomModels[] = $room;
        }
        $makeCamera = fn ($room) => Camera::create(['room_id' => $room->id, 'name' => 'Fixture kamera', 'source_type' => 'webcam', 'is_active' => false])->fresh();
        $cameraA = in_array($case, ['edit', 'reassign', 'history'], true) ? $makeCamera($roomModels[0]) : null;
        $cameraB = $case === 'reassign' ? $makeCamera($roomModels[1]) : $cameraA;
        $target = in_array($case, ['reassign', 'history'], true) ? $roomModels[2] : $roomModels[0];
        $actionA = match ($case) {
            'edit' => 'edit', 'reassign', 'history' => 'reassign', default => 'create'
        };
        $actionB = match ($case) {
            'room_deactivation' => 'deactivate_room', 'history' => 'record_detection', default => $actionA
        };
        $payload = fn ($index, $camera, $action) => ['actor_id' => $admins[$index]->id, 'room_id' => $target->id, 'class_id' => $class->id,
            'camera_id' => $camera?->id, 'current_room_id' => $camera?->room_id, 'version' => $camera ? CameraVersion::of($camera) : null,
            'name' => 'Edit '.$index, 'action' => $action];
        $this->guard();
        DB::beginTransaction();
        try {
            ServiceClass::whereKey($class->id)->lockForUpdate()->firstOrFail();
            foreach ([$payload(0, $cameraA, $actionA), $payload(1, $cameraB, $actionB)] as $data) {
                $process = new Process([PHP_BINARY, 'tests/Support/camera-worker.php', json_encode($data, JSON_THROW_ON_ERROR)], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
                $process->setTimeout(30);
                $process->start();
                $this->processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            $pids = [];
            do {
                foreach ($this->processes as $index => $process) {
                    $line = strtok($process->getOutput(), "\n");
                    if ($line && ($ready = json_decode($line, true)) && ($ready['ready'] ?? false)) {
                        $pids[$index] = $ready['pid'];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids, 'Worker yang gagal bootstrap bukan penolakan bisnis yang sah.');
            $this->assertNotSame($pids[0], $pids[1]);
            if ($case === 'history') {
                // Event masuk saat pemindahan masih tertahan class lock; bukan endpoint ingestion aplikasi.
                $this->processes[1]->wait();
                $this->assertTrue($this->processes[1]->isSuccessful());
                $this->assertSame(1, $cameraA->aiDetections()->count());
            }
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === ($case === 'history' ? 1 : 2)) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame($case === 'history' ? 1 : 2, $waiting, 'Interleaving harus dibuktikan melalui lock PostgreSQL.');
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Worker gagal karena infrastruktur.');
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
            if (! $result['ok']) {
                $this->assertSame('validation', $result['kind']);
            }
            $results[] = $result;
        }
        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $error = collect($results)->firstWhere('ok', false);
        $this->assertNotEmpty(array_intersect($case === 'edit' ? ['version'] : ($case === 'room_deactivation' ? ['room_id', 'status'] : ['room_id']), $error['fields']));
        $this->assertLessThanOrEqual(1, $target->camera()->count());
        if ($case === 'room_deactivation' && $target->camera()->exists()) {
            $this->assertTrue($target->fresh()->is_active);
        }
        if ($case === 'history') {
            $this->assertSame($roomModels[0]->id, $cameraA->fresh()->room_id);
            $this->assertSame(1, $cameraA->aiDetections()->count());
        }
        if ($case === 'edit') {
            $this->assertContains($cameraA->fresh()->name, ['Edit 0', 'Edit 1']);
        }
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
            $cameraIds = Camera::whereIn('room_id', $this->rooms)->pluck('id')->all();
            $this->guard();
            AiDetection::whereIn('camera_id', $cameraIds)->delete();
            $this->guard();
            Camera::whereIn('id', $cameraIds)->delete();
            $this->guard();
            Room::whereIn('id', $this->rooms)->delete();
            $this->guard();
            User::whereIn('id', $this->users)->delete();
            $this->guard();
            ServiceClass::whereIn('id', $this->classes)->delete();
        }
        parent::tearDown();
    }
}
