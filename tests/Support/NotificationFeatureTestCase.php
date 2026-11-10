<?php

namespace Tests\Support;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Models\FallIncident;
use App\Models\Notification;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Services\CameraOperatingPeriodService;
use App\Services\FallSafetyNotificationService;
use App\Support\StaffNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class NotificationFeatureTestCase extends PostgresFeatureTestCase
{
    protected User $admin;

    protected User $nurse;

    protected User $family;

    protected Room $room;

    protected Camera $camera;

    protected CameraOperatingPeriod $period;

    protected CarbonImmutable $eventTime;

    protected FallIncident $incident;

    private array $before = [];

    private const TABLES = ['payments', 'invoices', 'notifications', 'fall_incident_actions', 'fall_incidents', 'gait_observations',
        'ai_detections', 'camera_operating_periods', 'elderly_stays', 'service_enrollments', 'cameras', 'rooms', 'elderlies',
        'service_classes', 'users', 'jobs', 'failed_jobs'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLES as $table) {
            $this->before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $this->seed(ServiceClassSeeder::class);
        $this->admin = $this->account();
        $this->nurse = $this->account('perawat');
        $this->family = $this->account('keluarga');
        $class = ServiceClass::where('code', 'class_2')->sole();
        $this->room = Room::create(['service_class_id' => $class->id, 'code' => 'NTF-'.Str::uuid(), 'name' => 'Kamar fixture', 'capacity' => 4])->fresh();
        $this->camera = Camera::create(['room_id' => $this->room->id, 'name' => 'Kamera fixture',
            'source_type' => 'webcam', 'is_active' => false])->fresh();
        $this->eventTime = CameraOperatingPeriodService::serverTime()->subHour();
        $this->period = CameraOperatingPeriod::create(['camera_id' => $this->camera->id, 'room_id' => $this->room->id,
            'service_class_id' => $class->id, 'room_type' => 'shared', 'room_capacity' => 4, 'source_type' => 'webcam',
            'opened_at' => $this->eventTime->subDay(), 'closed_at' => $this->eventTime->addMinutes(30)])->fresh();
        $this->incident = $this->fall();
        $this->assertSame(1, DB::transactionLevel());
        DB::commit(); // Fan-out wajib di luar transaksi sumber; worker melihat fixture committed.
        $this->signIn($this->nurse);
    }

    protected function signIn(User $user): void
    {
        Auth::forgetGuards();
        session()->flush();
        $this->actingAs($user->fresh());
    }

    protected function fall(array $event = [], array $child = []): FallIncident
    {
        $detection = AiDetection::create(['camera_id' => $this->camera->id, 'camera_period_id' => $this->period->id,
            'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall', 'occurred_at' => $this->eventTime,
            'received_at' => $this->eventTime->addMinute(), 'metadata' => ['private' => 'PRIVATE-GATEWAY-SENTINEL'], ...$event])->fresh();

        return $detection->fallIncident()->create(['notes' => 'PRIVATE-STAFF-NOTE', ...$child])->fresh();
    }

    protected function notice(?User $user = null, ?FallIncident $incident = null, array $changes = []): Notification
    {
        return Notification::create(['type' => StaffNotification::KIND, 'notifiable_type' => StaffNotification::MORPH,
            'notifiable_id' => ($user ?? $this->nurse)->id, 'fall_incident_id' => ($incident ?? $this->incident)->id,
            'data' => ['schema_version' => 1, 'message' => StaffNotification::MESSAGE], ...$changes])->fresh();
    }

    protected function deliver(?FallIncident $incident = null): int
    {
        $this->guard();

        return app(FallSafetyNotificationService::class)->deliver(($incident ?? $this->incident)->id);
    }

    protected function snapshot(): array
    {
        $data = [];
        foreach (self::TABLES as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $data;
    }

    protected function guard(): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
    }

    protected function tearDown(): void
    {
        if ($this->before !== []) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (self::TABLES as $table) {
                $this->guard();
                DB::table($table)->whereNotIn('id', array_column($this->before[$table], 'id'))->delete();
            }
            foreach (self::TABLES as $table) {
                $this->assertSame($this->before[$table], DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), $table);
            }
        }
        parent::tearDown();
    }
}
