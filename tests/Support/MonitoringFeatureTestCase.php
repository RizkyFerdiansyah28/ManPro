<?php

namespace Tests\Support;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\CameraOperatingPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class MonitoringFeatureTestCase extends CameraFeatureTestCase
{
    protected Camera $monitorCamera;

    protected CameraOperatingPeriod $monitorPeriod;

    protected CarbonImmutable $eventTime;

    private int $roomNumber = 0;

    private ?User $caregiver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventTime = CameraOperatingPeriodService::serverTime()->subHour();
        $this->monitorCamera = $this->camera();
        $this->monitorPeriod = $this->periodFor($this->monitorCamera);
    }

    protected function room(?ServiceClass $class = null, array $changes = []): Room
    {
        return parent::room($class, ['code' => 'MON-'.(++$this->roomNumber), ...$changes]);
    }

    protected function periodFor(Camera $camera, array $changes = []): CameraOperatingPeriod
    {
        $room = $camera->room;

        return CameraOperatingPeriod::create(['camera_id' => $camera->id, 'room_id' => $room->id,
            'service_class_id' => $room->service_class_id, 'room_type' => $room->serviceClass->room_type,
            'room_capacity' => $room->capacity, 'source_type' => $camera->source_type,
            'opened_at' => $this->eventTime->subDays(300), 'closed_at' => $this->eventTime->addMinutes(30), ...$changes])->fresh();
    }

    protected function event(string $type = 'fall', array $changes = [], array $child = [], bool $withChild = true): AiDetection
    {
        $event = AiDetection::create(['camera_id' => $this->monitorCamera->id, 'camera_period_id' => $this->monitorPeriod->id,
            'external_event_id' => (string) Str::uuid(), 'event_type' => $type, 'occurred_at' => $this->eventTime,
            'received_at' => $this->eventTime->addMinutes(5), 'metadata' => ['private' => 'PRIVATE-GATEWAY-SENTINEL'], ...$changes])->fresh();
        if ($withChild) {
            if ($type === 'fall') {
                $event->fallIncident()->create(['notes' => 'PRIVATE-NOTES-SENTINEL', ...$child]);
            } else {
                $event->gaitObservation()->create(['category' => 'walking_normal', 'identity_basis' => 'unknown', ...$child]);
            }
        }

        return $event;
    }

    protected function fall(string $status = 'pending', bool $verified = false, ?Elderly $resident = null, array $changes = []): AiDetection
    {
        $child = ['status' => $status];
        if ($status !== 'pending') {
            $this->caregiver ??= $this->account('perawat');
            $child += ['responding_caregiver_id' => $this->caregiver->id, 'acknowledged_at' => $this->eventTime->addMinute()->startOfSecond()];
            if ($status === 'resolved' || $verified || $resident !== null) {
                $child += ['verified_by' => $this->caregiver->id, 'verified_at' => $this->eventTime->addMinutes(2)->startOfSecond(), 'elderly_id' => $resident?->id];
            }
            if (in_array($status, ['resolved', 'false_alarm'], true)) {
                $child['resolved_at'] = $this->eventTime->addMinutes(3)->startOfSecond();
            }
        }

        return $this->event('fall', $changes, $child);
    }

    protected function knownGait(array $changes = []): AiDetection
    {
        $room = $this->room($this->vip);
        $camera = $this->camera($room);
        $period = $this->periodFor($camera);
        $resident = $this->resident();
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->subHours(2)->startOfSecond()]);
        ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $this->vip->id,
            'monthly_price' => '123.45', 'started_at' => $this->eventTime->subHours(3)->startOfSecond()]);

        return $this->event('gait', ['camera_id' => $camera->id, 'camera_period_id' => $period->id, ...$changes],
            ['elderly_id' => $resident->id, 'identity_basis' => 'vip_room_single_resident']);
    }

    protected function monitoringSnapshot(): array
    {
        $rows = [];
        foreach (['users', 'elderlies', 'rooms', 'service_classes', 'camera_operating_periods', 'cameras', 'elderly_stays',
            'service_enrollments', 'ai_detections', 'gait_observations', 'fall_incidents', 'invoices', 'payments', 'notifications'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    protected function listing(array $filters = [], string $path = '/monitoring/records'): array
    {
        return $this->get($path.'?'.http_build_query($filters))->assertOk()->viewData('page')['props'];
    }
}
