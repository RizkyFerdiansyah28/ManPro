<?php

namespace Tests\Feature\AiGateway;

use App\Models\AiDetection;
use App\Models\ElderlyStay;
use App\Models\ServiceEnrollment;
use App\Services\CameraService;
use App\Support\CameraVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiGatewayFeatureTestCase;

class AiAttributionTest extends AiGatewayFeatureTestCase
{
    public static function categories(): array
    {
        return [['walking_normal'], ['walking_abnormal'], ['no_walking_detected']];
    }

    #[DataProvider('categories')]
    public function test_shared_room_gait_remains_unidentified_even_with_one_resident(string $category): void
    {
        $stay = $this->stay($this->testCamera->room);
        ServiceEnrollment::create(['elderly_id' => $stay->elderly_id, 'service_class_id' => $this->shared->id,
            'monthly_price' => '123.45', 'started_at' => $this->eventTime->subHours(2)->startOfSecond()]);
        $this->sendEvent($this->payload('gait', ['category' => $category]))->assertCreated();
        $observation = AiDetection::sole()->gaitObservation()->sole();
        $this->assertSame($category, $observation->category);
        $this->assertNull($observation->elderly_id);
        $this->assertSame('unknown', $observation->identity_basis);
        $this->assertDatabaseCount('fall_incidents', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function vipCases(): array
    {
        return [['matching'], ['none'], ['multiple'], ['overlap'], ['missing_enrollment'], ['wrong_class'], ['current_different'], ['inactive_now'], ['overlapping_enrollment']];
    }

    #[DataProvider('vipCases')]
    public function test_vip_identity_depends_on_historical_stay_and_enrollment_only(string $case): void
    {
        $room = $this->room($this->vip, ['code' => 'VIP-ATTR']);
        $camera = $this->camera($room);
        app(CameraService::class)->setActive($this->admin, $camera, CameraVersion::of($camera), true);
        $period = $camera->operatingPeriods()->sole();
        $period->update(['opened_at' => $this->eventTime->subHours(3)]);
        $this->configureSource(['camera_ids' => [$camera->id]]);
        $resident = $this->resident();
        $ended = in_array($case, ['current_different', 'overlap'], true) ? $this->eventTime->addSeconds(10)->startOfSecond() : null;
        if ($case !== 'none') {
            ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->subHour()->startOfSecond(), 'ended_at' => $ended]);
        }
        if ($case !== 'missing_enrollment') {
            ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $case === 'wrong_class' ? $this->shared->id : $this->vip->id,
                'monthly_price' => '123.45', 'started_at' => $this->eventTime->subHours(2)->startOfSecond(),
                'ended_at' => $case === 'overlapping_enrollment' ? $this->eventTime->addSeconds(10)->startOfSecond() : null]);
        }
        if ($case === 'overlapping_enrollment') {
            ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $this->shared->id,
                'monthly_price' => '123.45', 'started_at' => $this->eventTime->subHour()->startOfSecond()]);
        }
        if ($case === 'multiple') {
            $other = $this->account('keluarga')->elderlies()->create(['name' => 'Lansia kedua fixture', 'birth_date' => '1950-01-01']);
            ElderlyStay::create(['elderly_id' => $other->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->subHour()->startOfSecond()]);
        }
        if ($case === 'overlap') {
            ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->subMinutes(30)->startOfSecond()]);
        }
        if ($case === 'current_different') {
            $other = $this->account('keluarga')->elderlies()->create(['name' => 'Penghuni sekarang fixture', 'birth_date' => '1950-01-01']);
            ElderlyStay::create(['elderly_id' => $other->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->addSeconds(20)->startOfSecond()]);
        }
        if ($case === 'inactive_now') {
            $resident->update(['is_active' => false]); // Histori legacy tidak dihapus/diaktifkan otomatis.
            $resident->family->update(['is_active' => false]);
        }
        $this->sendEvent($this->payload('gait', ['camera_id' => $camera->id, 'camera_period_id' => $period->id]))->assertCreated();
        $observation = AiDetection::sole()->gaitObservation()->sole();
        $identified = in_array($case, ['matching', 'current_different', 'inactive_now'], true);
        $this->assertSame($identified ? $resident->id : null, $observation->elderly_id);
        $this->assertSame($identified ? 'vip_room_single_resident' : 'unknown', $observation->identity_basis);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_vip_fall_never_assigns_or_verifies_the_single_resident_automatically(): void
    {
        $room = $this->room($this->vip, ['code' => 'VIP-FALL']);
        $camera = $this->camera($room);
        app(CameraService::class)->setActive($this->admin, $camera, CameraVersion::of($camera), true);
        $period = $camera->operatingPeriods()->sole();
        $period->update(['opened_at' => $this->eventTime->subHour()]);
        $stay = $this->stay($room);
        ServiceEnrollment::create(['elderly_id' => $stay->elderly_id, 'service_class_id' => $this->vip->id, 'monthly_price' => '123.45',
            'started_at' => $this->eventTime->subHours(2)->startOfSecond()]);
        $this->configureSource(['camera_ids' => [$camera->id]]);
        $this->sendEvent($this->payload(changes: ['camera_id' => $camera->id, 'camera_period_id' => $period->id]))->assertCreated();
        $incident = AiDetection::sole()->fallIncident()->sole();
        $this->assertSame('pending', $incident->status);
        $this->assertNull($incident->elderly_id);
        $this->assertNull($incident->verified_at);
        $this->assertDatabaseCount('notifications', 0);
    }
}
