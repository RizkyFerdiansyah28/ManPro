<?php

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;

abstract class FacilityFeatureTestCase extends PostgresFeatureTestCase
{
    protected User $admin;

    protected ServiceClass $vip;

    protected ServiceClass $shared;

    protected ServiceClass $basic;

    private ?User $fixtureFamily = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceClassSeeder::class);
        $this->vip = ServiceClass::where('code', 'vip')->sole();
        $this->shared = ServiceClass::where('code', 'class_2')->sole();
        $this->basic = ServiceClass::where('code', 'class_3')->sole();
        $this->admin = $this->account();
        $this->actingAs($this->admin);
    }

    protected function roomPayload(?ServiceClass $class = null, array $changes = []): array
    {
        $class ??= $this->shared;

        return array_replace(['code' => 'TEST-01', 'name' => 'Kamar Uji', 'service_class_id' => $class->id,
            'capacity' => $class->room_type === 'private' ? 1 : 4], $changes);
    }

    protected function room(?ServiceClass $class = null, array $changes = []): Room
    {
        return Room::create($this->roomPayload($class, $changes))->fresh();
    }

    protected function roomUpdate(Room $room, array $changes = []): array
    {
        return array_replace(['code' => $room->code, 'name' => $room->name, 'service_class_id' => $room->service_class_id,
            'current_service_class_id' => $room->service_class_id, 'capacity' => $room->capacity, 'confirm_class_change' => false], $changes);
    }

    protected function resident(): Elderly
    {
        $this->fixtureFamily ??= $this->account('keluarga');

        return $this->fixtureFamily->elderlies()->create(['name' => 'Lansia Fixture', 'birth_date' => '1950-01-01']);
    }

    protected function stay(Room $room, bool $active = true): ElderlyStay
    {
        return $this->resident()->stays()->create(['room_id' => $room->id, 'started_at' => '2026-01-01T00:00:00+00:00',
            'ended_at' => $active ? null : '2026-02-01T00:00:00+00:00']);
    }

    protected function enrollment(ServiceClass $class, bool $active = true): ServiceEnrollment
    {
        return $this->resident()->serviceEnrollments()->create(['service_class_id' => $class->id, 'monthly_price' => '123.45',
            'started_at' => '2026-01-01T00:00:00+00:00', 'ended_at' => $active ? null : '2026-02-01T00:00:00+00:00']);
    }

    protected function historySnapshot(): array
    {
        $rows = [];
        foreach (['elderly_stays', 'service_enrollments', 'cameras', 'ai_detections', 'gait_observations', 'fall_incidents', 'invoices', 'payments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
