<?php

namespace Tests\Feature\Monitoring;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestGuard;
use Tests\TestCase;

class MonitoringConsistencyTest extends TestCase
{
    public function test_reads_do_not_wait_for_writer_and_reload_sees_complete_committed_event(): void
    {
        if (config('database.default') !== 'silansia_testing') {
            $this->markTestSkipped('Memerlukan runner PostgreSQL terisolasi.');
        }
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe($this->app, DB::connection());
        $this->assertSame(0, DB::transactionLevel());
        $this->withoutVite();
        $users = [];
        $room = $camera = $newEvent = $firstEvent = null;
        $createdClass = false;
        $writer = null;
        try {
            $class = ServiceClass::where('code', 'class_2')->first();
            if (! $class) {
                $class = ServiceClass::create(['code' => 'class_2', 'name' => 'Kelas fixture monitoring', 'room_type' => 'shared']);
                $createdClass = true;
            }
            $users[] = $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
            $users[] = $caregiver = User::factory()->create(['role' => 'perawat', 'is_active' => true]);
            $room = Room::create(['service_class_id' => $class->id, 'code' => 'MON-RACE-'.substr($admin->id, 0, 16), 'name' => 'Room fixture', 'capacity' => 4]);
            $camera = Camera::create(['room_id' => $room->id, 'name' => 'Camera fixture', 'source_type' => 'webcam', 'is_active' => false]);
            $instant = DB::scalar("SELECT clock_timestamp() - interval '1 hour'");
            $firstEvent = AiDetection::create(['camera_id' => $camera->id, 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall', 'occurred_at' => $instant]);
            $firstEvent->fallIncident()->create([]);
            $writer = (new ConnectionFactory(new Container))->make(DB::connection()->getConfig(), 'silansia_testing');
            $guard->assertSafe($this->app, $writer);
            $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $writer->scalar('SELECT pg_backend_pid()'));
            $writer->beginTransaction();
            $writer->table('fall_incidents')->where('ai_detection_id', $firstEvent->id)->update([
                'status' => 'acknowledged', 'responding_caregiver_id' => $caregiver->id, 'acknowledged_at' => $instant]);
            $newEvent = (string) Str::uuid();
            $writer->table('ai_detections')->insert(['id' => $newEvent, 'camera_id' => $camera->id, 'external_event_id' => (string) Str::uuid(),
                'event_type' => 'fall', 'occurred_at' => $instant]);
            $writer->table('fall_incidents')->insert(['id' => (string) Str::uuid(), 'ai_detection_id' => $newEvent]);
            $this->actingAs($admin);
            DB::statement("SET statement_timeout = '2s'");
            $before = $this->get('/admin')->assertOk()->viewData('page')['props'];
            $this->assertSame(1, $before['stats']['fall_detections']);
            $this->assertSame(['pending' => 1, 'acknowledged' => 0], $before['open_incidents']);
            $this->assertSame('pending', $before['recent'][0]['fall']['status']);
            $guard->assertSafe($this->app, $writer);
            $writer->commit();
            $after = $this->get('/admin')->assertOk()->viewData('page')['props'];
            $this->assertSame(2, $after['stats']['fall_detections']);
            $this->assertCount(2, $after['recent']);
            $this->assertSame(['pending' => 1, 'acknowledged' => 1], $after['open_incidents']);
            $this->assertSame(2, $this->get('/monitoring/open-incidents')->assertOk()->viewData('page')['props']['records']['total']);
        } finally {
            if ($writer) {
                while ($writer->transactionLevel() > 0) {
                    $writer->rollBack();
                }
                $writer->disconnect();
            }
            $guard->assertSafe($this->app, DB::connection());
            DB::statement('SET statement_timeout = 0');
            $ids = array_filter([$firstEvent?->id, $newEvent]);
            DB::table('fall_incidents')->whereIn('ai_detection_id', $ids)->delete();
            DB::table('ai_detections')->whereIn('id', $ids)->delete();
            if ($camera) {
                $camera->delete();
            }
            if ($room) {
                $room->delete();
            }
            foreach ($users as $user) {
                $user->delete();
            }
            if ($createdClass) {
                $class->delete();
            }
        }
    }
}
