<?php

namespace Tests\Feature\Incidents;

use App\Models\ElderlyStay;
use App\Models\FallIncident;
use App\Services\AiIngestionService;
use App\Support\AiGateway\EventPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IncidentFeatureTestCase;
use Tests\Support\PostgresTestGuard;

class IncidentConcurrencyTest extends IncidentFeatureTestCase
{
    private array $processes = [];

    private array $owned = [];

    private const CLEANUP = ['fall_incident_actions', 'fall_incidents', 'gait_observations', 'ai_detections',
        'camera_operating_periods', 'elderly_stays', 'service_enrollments', 'cameras', 'rooms', 'elderlies', 'service_classes', 'users'];

    public function test_gateway_duplicate_during_uncommitted_human_action_does_not_reset_history(): void
    {
        $source = ['id' => (string) Str::uuid(), 'key_id' => (string) Str::uuid(),
            'camera_ids' => [$this->monitorCamera->id], 'event_types' => ['fall']];
        $payload = ['contract_version' => '1', 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall',
            'camera_id' => $this->monitorCamera->id, 'camera_period_id' => $this->monitorPeriod->id,
            'occurred_at' => $this->eventTime->format('Y-m-d\TH:i:s.u\Z'), 'confidence' => '0.85000'];
        $accepted = app(AiIngestionService::class)->ingest(new EventPayload($payload), $source);
        $this->incident = FallIncident::where('ai_detection_id', $accepted['detection_id'])->sole();
        $before = $this->incident->aiDetection->getRawOriginal();
        $command = ['actor_id' => $this->nurse->id, 'incident_id' => $this->incident->id, 'action' => 'acknowledge',
            'input' => $this->command('acknowledge'), 'hold_at_journal' => true];
        $this->commitFixtures();
        $human = $this->worker($command);
        $this->checkpoint($human, 'locked');
        // Insiden telah di-update tetapi transaksi human belum commit.
        $duplicate = $this->worker(['actor_id' => $this->admin->id, 'action' => 'gateway_duplicate', 'source' => $source, 'payload' => $payload]);
        $duplicate->wait();
        $this->assertTrue($duplicate->isSuccessful());
        $lines = array_filter(explode("\n", trim($duplicate->getOutput())));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['duplicate']);
        $readyHuman = json_decode(strtok($human->getOutput(), "\n"), true);
        $readyAi = json_decode(strtok($duplicate->getOutput(), "\n"), true);
        $this->assertNotSame($readyHuman['pid'], $readyAi['pid']);
        // Lepaskan worker melalui InputStream yang dipasang sebelum start.
        $this->releaseStream->write('release'.PHP_EOL);
        $this->releaseStream->close();
        $human->wait();
        $this->assertTrue($human->isSuccessful());
        $this->assertSame('acknowledged', $this->incident->fresh()->status);
        $this->assertSame($this->nurse->id, $this->incident->fresh()->responding_caregiver_id);
        $this->assertSame($before, $this->incident->fresh()->aiDetection->getRawOriginal());
        $this->assertSame(1, $this->incident->actions()->count());
    }

    private ?InputStream $releaseStream = null;

    private function worker(array $input): Process
    {
        $this->guard();
        $process = new Process([PHP_BINARY, 'tests/Support/incident-worker.php', json_encode($input, JSON_THROW_ON_ERROR)],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
        $process->setTimeout(30);
        if ($input['hold_at_journal'] ?? false) {
            $this->releaseStream = new InputStream;
            $process->setInput($this->releaseStream);
        }
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function checkpoint(Process $process, string $key): void
    {
        $deadline = microtime(true) + 10;
        do {
            foreach (array_filter(explode("\n", $process->getOutput())) as $line) {
                if (json_decode($line, true)[$key] ?? false) {
                    return;
                }
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker tidak mencapai checkpoint transaksi yang diharapkan.');
    }

    private function commitFixtures(): void
    {
        $this->guard();
        foreach (self::CLEANUP as $table) {
            $this->owned[$table] = DB::table($table)->pluck('id')->all();
        }
        unset($this->owned['fall_incident_actions']);
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    public static function races(): array
    {
        return [['ack'], ['duplicate'], ['verify_false'], ['verify_identity'], ['resolve_stale'], ['deactivate'], ['stay_close']];
    }

    #[DataProvider('races')]
    public function test_real_processes_serialize_incident_and_related_operations(string $case): void
    {
        $this->guard();
        $second = $this->account('perawat');
        $resident = null;
        $otherResident = null;
        $stay = null;
        if (in_array($case, ['verify_false', 'verify_identity', 'resolve_stale', 'stay_close'], true)) {
            $this->perform('acknowledge');
        }
        if ($case === 'resolve_stale') {
            $this->perform('verify');
        }
        if (in_array($case, ['verify_identity', 'stay_close'], true)) {
            $resident = $this->resident();
            $stay = ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $this->monitorPeriod->room_id, 'started_at' => $this->eventTime->subDay()]);
            if ($case === 'verify_identity') {
                $otherResident = $this->resident();
                ElderlyStay::create(['elderly_id' => $otherResident->id, 'room_id' => $this->monitorPeriod->room_id, 'started_at' => $this->eventTime->subDay()]);
            }
        }
        $firstAction = match ($case) {
            'verify_false', 'verify_identity', 'stay_close' => 'verify', 'resolve_stale' => 'resolve', default => 'acknowledge'
        };
        $one = ['actor_id' => $this->nurse->id, 'incident_id' => $this->incident->id, 'action' => $firstAction,
            'input' => $this->command($firstAction, $resident ? ['identity_mode' => 'known', 'elderly_id' => $resident->id] : [])];
        $two = [...$one, 'actor_id' => $second->id, 'input' => [...$one['input'], 'request_id' => (string) Str::uuid()]];
        if ($case === 'duplicate') {
            $two = $one;
        } elseif (in_array($case, ['verify_false', 'resolve_stale'], true)) {
            $two['action'] = 'false_alarm';
            $two['input'] = $this->command('false_alarm');
        } elseif ($case === 'verify_identity') {
            $two['input']['elderly_id'] = $otherResident->id;
        } elseif ($case === 'deactivate') {
            $two = ['actor_id' => $this->admin->id, 'target_id' => $this->nurse->id, 'action' => 'deactivate'];
        } elseif ($case === 'stay_close') {
            $two = ['actor_id' => $this->admin->id, 'elderly_id' => $resident->id, 'stay_id' => $stay->id, 'action' => 'close_stay'];
        }
        $beforeAi = $this->incident->aiDetection->getRawOriginal();
        $initialActions = DB::table('fall_incident_actions')->count();
        $this->commitFixtures();
        $this->guard();
        DB::beginTransaction();
        try {
            DB::table('service_classes')->where('id', $this->monitorPeriod->service_class_id)->lockForUpdate()->first();
            DB::table('rooms')->where('id', $this->monitorPeriod->room_id)->lockForUpdate()->first();
            if ($case === 'deactivate') {
                DB::table('users')->where('id', $this->nurse->id)->lockForUpdate()->first();
            }
            DB::table('fall_incidents')->where('id', $this->incident->id)->lockForUpdate()->first();
            foreach ([$one, $two] as $input) {
                $process = new Process([PHP_BINARY, 'tests/Support/incident-worker.php', json_encode($input, JSON_THROW_ON_ERROR)], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
                $process->setTimeout(30);
                $process->start();
                $this->processes[] = $process;
            }
            $pids = [];
            $deadline = microtime(true) + 10;
            do {
                foreach ($this->processes as $index => $process) {
                    $line = strtok($process->getOutput(), "\n");
                    $ready = $line ? json_decode($line, true) : null;
                    if ($ready['ready'] ?? false) {
                        $pids[$index] = $ready['pid'];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids, 'Dua worker harus siap; error infrastruktur bukan hasil negatif yang sah.');
            $this->assertNotSame($pids[0], $pids[1]);
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Kedua proses harus benar-benar menunggu lock sebelum dilepas.');
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Worker mengalami kegagalan infrastruktur.');
            $lines = array_filter(explode("\n", trim($process->getOutput())));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertContains($result['kind'] ?? 'success', ['success', 'conflict', 'authorization', 'validation']);
            $results[] = $result;
        }
        $successes = count(array_filter($results, fn ($result) => $result['ok']));
        if (in_array($case, ['duplicate', 'stay_close'], true)) {
            $this->assertSame(2, $successes);
        } elseif ($case === 'deactivate') {
            $this->assertFalse($this->nurse->fresh()->is_active);
            $this->assertContains($successes, [1, 2]);
        } else {
            $this->assertSame(1, $successes);
            $this->assertSame('conflict', collect($results)->firstWhere('ok', false)['kind']);
        }
        $count = DB::table('fall_incident_actions')->count();
        $this->assertSame($initialActions + ($case === 'deactivate' && $successes === 1 ? 0 : 1), $count);
        if ($case === 'duplicate') {
            $this->assertSame(1, count(array_filter($results, fn ($result) => $result['duplicate'])));
        }
        if ($case === 'stay_close') {
            $this->assertNotNull($stay->fresh()->ended_at);
            $this->assertSame($resident->id, $this->incident->fresh()->elderly_id);
        }
        if ($case === 'verify_identity') {
            $this->assertContains($this->incident->fresh()->elderly_id, [$resident->id, $otherResident->id]);
        }
        $this->assertSame($beforeAi, $this->incident->fresh()->aiDetection->getRawOriginal());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function guard(): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if ($this->owned !== []) {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            $this->guard();
            DB::table('fall_incident_actions')->whereIn('fall_incident_id', $this->owned['fall_incidents'])->delete();
            foreach (self::CLEANUP as $table) {
                if ($table !== 'fall_incident_actions') {
                    $this->guard();
                    DB::table($table)->whereIn('id', $this->owned[$table])->delete();
                }
            }
        }
        parent::tearDown();
    }
}
