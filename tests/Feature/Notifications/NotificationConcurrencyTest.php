<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\CameraService;
use App\Services\FallSafetyNotificationService;
use App\Support\CameraVersion;
use App\Support\IncidentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationFeatureTestCase;

class NotificationConcurrencyTest extends NotificationFeatureTestCase
{
    private array $processes = [];

    public static function races(): array
    {
        return [['deliver_twice'], ['read_twice'], ['read_all_twice'], ['read_insert'],
            ['deactivate'], ['acknowledge'], ['camera'], ['activate']];
    }

    #[DataProvider('races')]
    public function test_real_processes_preserve_notification_lifecycle_and_lock_order(string $case): void
    {
        $notice = null;
        $target = $this->incident;
        if (in_array($case, ['read_twice', 'read_all_twice', 'read_insert'])) {
            $notice = $this->notice();
            if ($case === 'read_all_twice') {
                $this->notice(incident: $this->fall());
            }
            if ($case === 'read_insert') {
                $target = $this->fall();
            }
        }
        $new = $case === 'activate' ? $this->account('perawat', false) : null;
        if ($case === 'camera') {
            app(CameraService::class)->setActive($this->admin, $this->camera, CameraVersion::of($this->camera->fresh()), true);
        }
        $base = ['action' => 'deliver', 'incident_id' => $target->id];
        $one = $base;
        $two = match ($case) {
            'read_twice' => ['action' => 'read', 'actor_id' => $this->nurse->id, 'notification_id' => $notice->id],
            'read_all_twice', 'read_insert' => ['action' => 'read_all', 'actor_id' => $this->nurse->id],
            'deactivate' => ['action' => 'deactivate', 'actor_id' => $this->admin->id, 'target_id' => $this->nurse->id],
            'activate' => ['action' => 'activate', 'actor_id' => $this->admin->id, 'target_id' => $new->id],
            'acknowledge' => ['action' => 'acknowledge', 'actor_id' => $this->nurse->id, 'incident_id' => $target->id,
                'input' => ['request_id' => (string) Str::uuid(), 'expected_version' => IncidentVersion::of($target->fresh()), 'confirm' => true, 'notes' => null]],
            'camera' => ['action' => 'camera_deactivate', 'actor_id' => $this->admin->id,
                'camera_id' => $this->camera->id, 'version' => CameraVersion::of($this->camera->fresh())],
            default => $base,
        };
        if (in_array($case, ['read_twice', 'read_all_twice'])) {
            $one = $two;
        }
        $eventBefore = $target->aiDetection->getRawOriginal();
        $this->guard();
        DB::beginTransaction();
        try {
            User::whereIn('id', array_filter([$this->admin->id, $this->nurse->id, $new?->id]))->orderBy('id')->lockForUpdate()->get();
            $streams = [];
            foreach ([$one, $two] as $data) {
                $process = new Process([PHP_BINARY, 'tests/Support/notification-worker.php', json_encode([...$data, 'start_barrier' => true], JSON_THROW_ON_ERROR)],
                    base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
                $stream = new InputStream;
                $process->setInput($stream);
                $process->setTimeout(30);
                $process->start();
                $streams[] = $stream;
                $this->processes[] = $process;
            }
            $pids = [];
            $deadline = microtime(true) + 12;
            do {
                foreach ($this->processes as $i => $process) {
                    $line = strtok($process->getOutput(), "\n");
                    $ready = $line ? json_decode($line, true) : null;
                    if ($ready['ready'] ?? false) {
                        $pids[$i] = $ready['pid'];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids);
            $this->assertNotSame($pids[0], $pids[1]);
            foreach ($streams as $stream) {
                $stream->write('go'.PHP_EOL);
                $stream->close();
            }
            $deadline = microtime(true) + 1.5;
            do {
                foreach ($this->processes as $process) {
                    $process->getOutput();
                } // Pump InputStream tanpa tebakan urutan.
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Kedua worker harus benar-benar menunggu PostgreSQL lock.');
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Worker gagal infrastruktur, bukan hasil negatif bisnis.');
            $lines = array_filter(explode("\n", trim($process->getOutput())));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok']);
            $results[] = $result['result'];
        }
        if ($case === 'deliver_twice') {
            $this->assertEqualsCanonicalizing([2, 0], $results);
            $this->assertDatabaseCount('notifications', 2);
        } elseif (in_array($case, ['read_twice', 'read_all_twice'])) {
            $this->assertEqualsCanonicalizing([$case === 'read_twice' ? 1 : 2, 0], $results);
            $this->assertSame(0, DB::table('notifications')->whereNull('read_at')->count());
        } elseif ($case === 'read_insert') {
            $newNotice = DB::table('notifications')->where('fall_incident_id', $target->id)->where('notifiable_id', $this->nurse->id)->sole();
            $this->assertContains($results[1], [1, 2]);
            $this->assertSame($results[1] === 1, $newNotice->read_at === null);
            $this->assertNotNull($notice->fresh()->read_at);
        } elseif ($case === 'deactivate') {
            $this->assertFalse($this->nurse->fresh()->is_active);
            $this->assertContains($results[0], [1, 2]);
            $this->assertSame($results[0], DB::table('notifications')->count());
        } elseif ($case === 'acknowledge') {
            $this->assertSame('acknowledged', $target->fresh()->status);
            $this->assertContains($results[0], [0, 2]);
            $this->assertSame($results[0], DB::table('notifications')->count());
        } elseif ($case === 'camera') {
            $this->assertFalse($this->camera->fresh()->is_active);
            $this->assertSame(2, DB::table('notifications')->count());
        } elseif ($case === 'activate') {
            $this->assertTrue($new->fresh()->is_active);
            app(FallSafetyNotificationService::class)->reconcile();
            $this->assertDatabaseCount('notifications', 3);
        }
        $this->assertSame($eventBefore, $target->fresh()->aiDetection->getRawOriginal());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->family->id)->count());
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        parent::tearDown();
    }
}
