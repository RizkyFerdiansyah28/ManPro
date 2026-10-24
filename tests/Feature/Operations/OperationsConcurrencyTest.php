<?php

namespace Tests\Feature\Operations;

use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestGuard;
use Tests\TestCase;

class OperationsConcurrencyTest extends TestCase
{
    private bool $verified = false;

    private array $userIds = [];

    private array $elderlyIds = [];

    private array $roomIds = [];

    private array $classIds = [];

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'silansia_testing') {
            $this->markTestSkipped('Memerlukan runner PostgreSQL terisolasi.');
        }
        $this->guard();
        $this->verified = true;
        // Fixtures harus committed supaya terlihat oleh koneksi worker terpisah.
        $this->assertSame(0, DB::transactionLevel());
        $before = ServiceClass::pluck('id')->all();
        $this->seed(ServiceClassSeeder::class);
        $this->classIds = ServiceClass::whereNotIn('id', $before)->pluck('id')->all();
    }

    private function guard(): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
    }

    private function account(string $role): User
    {
        $this->guard();
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $this->userIds[] = $user->id;

        return $user;
    }

    private function resident(User $family): Elderly
    {
        $this->guard();
        $elderly = $family->elderlies()->create(['name' => 'Fixture konkurensi', 'birth_date' => '1950-01-01']);
        $this->elderlyIds[] = $elderly->id;

        return $elderly;
    }

    public static function races(): array
    {
        return [['vip'], ['shared'], ['duplicate_stay'], ['duplicate_enrollment'], ['close_vs_start'], ['double_close'], ['capacity_vs_start'], ['deactivate_vs_enroll']];
    }

    #[DataProvider('races')]
    public function test_separate_processes_preserve_operational_invariants(string $case): void
    {
        $adminA = $this->account('admin');
        $adminB = $this->account('admin');
        $this->assertNotSame($adminA->id, $adminB->id);
        $family = $this->account('keluarga');
        $one = $this->resident($family);
        $two = $this->resident($family);
        $class = ServiceClass::where('code', $case === 'vip' ? 'vip' : 'class_2')->sole();
        // Kelas ini dibuat hanya oleh testcase pada DB milik runner.
        $this->assertContains($class->id, $this->classIds);
        $this->guard();
        $class->update(['monthly_price' => '123.45']);
        $capacity = match ($case) {
            'vip' => 1, 'duplicate_stay' => 3, default => 2
        };
        $room = $class->rooms()->create(['code' => 'R-'.$one->id, 'name' => 'Kamar fixture', 'capacity' => $capacity]);
        $this->roomIds[] = $room->id;
        $makeEnrollment = function (Elderly $elderly) use ($class) {
            return $elderly->serviceEnrollments()->create(['service_class_id' => $class->id, 'monthly_price' => '123.45', 'started_at' => now()->subDay()]);
        };
        $enrollmentA = in_array($case, ['duplicate_enrollment', 'deactivate_vs_enroll'], true) ? null : $makeEnrollment($one);
        $enrollmentB = in_array($case, ['vip', 'shared'], true) ? $makeEnrollment($two) : $enrollmentA;
        if (in_array($case, ['shared', 'capacity_vs_start'], true)) {
            $occupant = $this->resident($family);
            $makeEnrollment($occupant);
            $occupant->stays()->create(['room_id' => $room->id, 'started_at' => now()->subHour()]);
        }
        $stay = $case === 'double_close' ? $one->stays()->create(['room_id' => $room->id, 'started_at' => now()->subHour()]) : null;
        $firstAction = match ($case) {
            'duplicate_enrollment', 'deactivate_vs_enroll' => 'enroll', 'double_close' => 'end_stay', default => 'start'
        };
        $secondAction = match ($case) {
            'close_vs_start' => 'end_enrollment', 'capacity_vs_start' => 'reduce_capacity', 'deactivate_vs_enroll' => 'deactivate', default => $firstAction
        };
        $payload = fn ($actor, $elderly, $enrollment, $action) => ['actor_id' => $actor->id, 'elderly_id' => $elderly->id,
            'class_id' => $class->id, 'room_id' => $room->id, 'enrollment_id' => $enrollment?->id, 'stay_id' => $stay?->id, 'action' => $action];

        $this->guard();
        DB::beginTransaction();
        try {
            ServiceClass::whereKey($class->id)->lockForUpdate()->firstOrFail();
            Elderly::whereKey($one->id)->lockForUpdate()->firstOrFail();
            foreach ([$payload($adminA, $one, $enrollmentA, $firstAction),
                $payload($adminB, in_array($case, ['vip', 'shared'], true) ? $two : $one, $enrollmentB, $secondAction)] as $data) {
                $process = new Process([PHP_BINARY, 'tests/Support/operations-worker.php', json_encode($data, JSON_THROW_ON_ERROR)], base_path(),
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
            $this->assertCount(2, $pids, 'Kedua worker harus menyatakan siap; error infrastruktur bukan hasil negatif yang sah.');
            $this->assertNotSame($pids[0], $pids[1]);
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Kedua proses harus benar-benar menunggu lock PostgreSQL sebelum dilepas.');
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
            if (! ($result['ok'] ?? false)) {
                $this->assertSame('validation', $result['kind'] ?? null);
            }
            $results[] = $result;
        }
        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $error = collect($results)->firstWhere('ok', false);
        $expected = match ($case) {
            'vip', 'shared' => ['room_id'], 'duplicate_stay' => ['stay'], 'duplicate_enrollment' => ['enrollment'],
            'close_vs_start' => ['operation', 'enrollment_id'], 'double_close' => ['operation'],
            'capacity_vs_start' => ['capacity', 'room_id'], 'deactivate_vs_enroll' => ['status', 'resident'],
        };
        $this->assertNotEmpty(array_intersect($expected, $error['fields']));
        $this->assertLessThanOrEqual($room->fresh()->capacity, $room->activeStays()->count());
        $this->assertLessThanOrEqual(1, $one->activeStay()->count());
        $this->assertLessThanOrEqual(1, $one->activeServiceEnrollment()->count());
        if ($one->activeStay()->exists()) {
            $this->assertSame($room->service_class_id, $one->activeServiceEnrollment()->sole()->service_class_id);
            $this->assertTrue($one->fresh()->is_active);
        }
        if ($case === 'double_close') {
            $this->assertNotNull($stay->fresh()->ended_at);
            $this->assertNull($enrollmentA->fresh()->ended_at);
        }
        if ($case === 'deactivate_vs_enroll' && ! $one->fresh()->is_active) {
            $this->assertSame(0, $one->activeServiceEnrollment()->count());
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
            ElderlyStay::whereIn('elderly_id', $this->elderlyIds)->delete();
            $this->guard();
            ServiceEnrollment::whereIn('elderly_id', $this->elderlyIds)->delete();
            $this->guard();
            Room::whereIn('id', $this->roomIds)->delete();
            $this->guard();
            Elderly::whereIn('id', $this->elderlyIds)->delete();
            $this->guard();
            User::whereIn('id', $this->userIds)->delete();
            $this->guard();
            ServiceClass::whereIn('id', $this->classIds)->delete();
        }
        parent::tearDown();
    }
}
