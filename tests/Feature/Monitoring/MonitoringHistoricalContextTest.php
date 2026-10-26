<?php

namespace Tests\Feature\Monitoring;

use App\Models\ElderlyStay;
use App\Models\ServiceEnrollment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MonitoringFeatureTestCase;

class MonitoringHistoricalContextTest extends MonitoringFeatureTestCase
{
    public function test_event_uses_old_period_after_camera_move_and_master_rename(): void
    {
        $oldRoom = $this->monitorCamera->room;
        $newRoom = $this->room($this->vip);
        // Move precedes the late historical event; fixture does not alter production services.
        $this->monitorCamera->update(['room_id' => $newRoom->id, 'name' => 'Camera label now']);
        $this->fall();
        $oldRoom->update(['code' => 'RENAMED-OLD', 'name' => 'Master label now', 'capacity' => 6, 'is_active' => false]);
        $this->shared->update(['name' => 'Class label now', 'is_active' => false]);
        $row = $this->listing()['records']['data'][0];
        $this->assertSame('tracked', $row['context']['state']);
        $this->assertSame($oldRoom->id, $row['context']['room']['id']);
        $this->assertSame('RENAMED-OLD', $row['context']['room']['code']);
        $this->assertSame('Class label now', $row['context']['service_class']['name']);
        $this->assertSame('class_2', $row['context']['service_class']['code']);
        $this->assertSame(4, $this->monitorPeriod->fresh()->room_capacity);
        $this->assertSame(0, $this->listing(['room_id' => $newRoom->id])['records']['total']);
        $this->assertSame(1, $this->listing(['room_id' => $oldRoom->id, 'search' => 'RENAMED-OLD'])['records']['total']);
        $this->assertSame(0, $this->listing(['class_code' => 'vip'])['records']['total']);
    }

    public function test_legacy_event_never_infers_current_room_or_gait_identity(): void
    {
        $event = $this->knownGait(['camera_period_id' => null]);
        $row = $this->listing(['location' => 'legacy'])['records']['data'][0];
        $this->assertSame($event->id, $row['id']);
        $this->assertNull($row['context']['room']);
        $this->assertNull($row['context']['service_class']);
        $this->assertSame(['state' => 'unknown', 'name' => null, 'basis' => 'unknown'], $row['identity']);
        $this->assertSame(0, $this->listing(['identity' => 'known'])['records']['total']);
        $this->assertSame(0, $this->listing(['class_code' => 'vip'])['records']['total']);
    }

    public static function boundaries(): array
    {
        return [['at_open', 'tracked'], ['before_open', 'unavailable'], ['at_close', 'unavailable'], ['after_close', 'unavailable']];
    }

    #[DataProvider('boundaries')]
    public function test_period_uses_half_open_interval(string $case, string $state): void
    {
        $this->monitorPeriod->update(['opened_at' => $this->eventTime, 'closed_at' => $this->eventTime->addMinute()]);
        $time = match ($case) {
            'at_open' => $this->eventTime, 'before_open' => $this->eventTime->subMicrosecond(),
            'at_close' => $this->eventTime->addMinute(), default => $this->eventTime->addMinute()->addMicrosecond(),
        };
        $this->fall(changes: ['occurred_at' => $time]);
        $row = $this->listing(['location' => $state])['records']['data'][0];
        $this->assertSame($state, $row['context']['state']);
        $this->assertSame($state === 'tracked' ? $this->monitorPeriod->room_id : null, $row['context']['room']['id'] ?? null);
    }

    public static function identities(): array
    {
        return [['valid', true], ['closed_history', true], ['inactive_now', true], ['shared_snapshot', false],
            ['overlapping_stay', false], ['wrong_enrollment', false], ['overlapping_enrollment', false], ['outside_period', false]];
    }

    #[DataProvider('identities')]
    public function test_gait_identity_is_consistent_with_historical_context(string $case, bool $known): void
    {
        $event = $this->knownGait();
        $gait = $event->gaitObservation;
        $period = $event->cameraPeriod;
        $stay = ElderlyStay::where('elderly_id', $gait->elderly_id)->sole();
        $enrollment = ServiceEnrollment::where('elderly_id', $gait->elderly_id)->sole();
        switch ($case) {
            case 'closed_history':
                $stay->update(['ended_at' => $this->eventTime->addMinute()->startOfSecond()]);
                $enrollment->update(['ended_at' => $this->eventTime->addMinutes(2)->startOfSecond()]);
                ElderlyStay::create(['elderly_id' => $this->resident()->id, 'room_id' => $period->room_id, 'started_at' => $this->eventTime->addMinutes(3)->startOfSecond()]);
                break;
            case 'inactive_now':
                $gait->elderly->update(['is_active' => false]);
                $gait->elderly->family->update(['is_active' => false]);
                break;
            case 'shared_snapshot':
                $period->update(['room_type' => 'shared', 'room_capacity' => 4]);
                break;
            case 'overlapping_stay':
                $stay->update(['ended_at' => $this->eventTime->addMinute()->startOfSecond()]);
                ElderlyStay::create(['elderly_id' => $gait->elderly_id, 'room_id' => $period->room_id, 'started_at' => $this->eventTime->subMinutes(10)->startOfSecond()]);
                break;
            case 'wrong_enrollment':
                $enrollment->update(['service_class_id' => $this->shared->id]);
                break;
            case 'overlapping_enrollment':
                $enrollment->update(['ended_at' => $this->eventTime->addMinute()->startOfSecond()]);
                ServiceEnrollment::create(['elderly_id' => $gait->elderly_id, 'service_class_id' => $this->shared->id, 'monthly_price' => '55.00', 'started_at' => $this->eventTime->subMinutes(10)->startOfSecond()]);
                break;
            case 'outside_period':
                $period->update(['closed_at' => $this->eventTime]);
                break;
        }
        $before = $this->monitoringSnapshot();
        $row = $this->listing()['records']['data'][0];
        $this->assertSame($known ? 'known' : 'unknown', $row['identity']['state']);
        $this->assertSame($known ? $gait->elderly->name : null, $row['identity']['name']);
        $this->assertSame($known ? 1 : 0, $this->listing(['identity' => 'known'])['records']['total']);
        $this->assertSame($known ? 0 : 1, $this->listing(['identity' => 'unknown'])['records']['total']);
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public function test_verified_fall_identity_is_read_without_vip_inference_or_new_verification(): void
    {
        $resident = $this->resident();
        $pending = $this->fall();
        $resolved = $this->fall('resolved', resident: $resident, changes: ['camera_period_id' => null]);
        $ack = $this->fall('acknowledged');
        $false = $this->fall('false_alarm');
        $before = $this->monitoringSnapshot();
        $rows = collect($this->listing()['records']['data'])->keyBy('id');
        $this->assertSame($resident->name, $rows[$resolved->id]['identity']['name']);
        $this->assertSame('legacy', $rows[$resolved->id]['context']['state']);
        $this->assertTrue($rows[$resolved->id]['fall']['is_verified']);
        foreach ([$pending, $ack, $false] as $event) {
            $this->assertSame('unknown', $rows[$event->id]['identity']['state']);
            $this->assertFalse($rows[$event->id]['fall']['is_verified']);
        }
        $this->assertSame($before, $this->monitoringSnapshot());
    }
}
