<?php

namespace Tests\Feature\Monitoring;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitoringFeatureTestCase;

class MonitoringSummaryTest extends MonitoringFeatureTestCase
{
    public function test_empty_statistics_and_camera_counts_are_defined(): void
    {
        $props = $this->get('/admin')->assertOk()->viewData('page')['props'];
        $this->assertSame(['fall_detections' => 0, 'gait_observations' => 0, 'pending' => 0, 'acknowledged' => 0, 'resolved' => 0, 'false_alarm' => 0, 'verified' => 0], $props['stats']);
        $this->assertSame(['registered' => 1, 'active' => 0, 'inactive' => 1], $props['cameras']);
        $this->assertSame(['pending' => 0, 'acknowledged' => 0], $props['open_incidents']);
        $this->assertSame([], $props['recent']);
        $this->assertSame([], $props['pending_preview']);
    }

    public function test_statuses_verification_and_observation_counts_do_not_double_count(): void
    {
        $pending = $this->fall();
        $this->fall('acknowledged');
        $this->fall('acknowledged', true);
        $this->fall('resolved');
        $this->fall('false_alarm');
        $this->event('gait');
        $this->event('gait', [], ['category' => 'walking_abnormal']);
        $this->event('gait', [], ['category' => 'no_walking_detected']);
        // Multiple notification children must not multiply a detection.
        foreach (range(1, 3) as $i) {
            Notification::create(['type' => 'Fixture', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $this->admin->id,
                'fall_incident_id' => $pending->fallIncident->id, 'data' => ['private' => 'PRIVATE-NOTIFICATION']]);
        }
        $props = $this->get('/admin')->assertOk()->viewData('page')['props'];
        $this->assertSame(['fall_detections' => 5, 'gait_observations' => 3, 'pending' => 1, 'acknowledged' => 2, 'resolved' => 1, 'false_alarm' => 1, 'verified' => 2], $props['stats']);
        $this->assertCount(8, $props['recent']);
        $resolved = collect($props['recent'])->first(fn ($r) => ($r['fall']['status'] ?? null) === 'resolved');
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved['fall']['is_verified']);
        $this->assertSame('unknown', $resolved['identity']['state']);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_old_open_incidents_remain_visible_independently_of_time_filters(): void
    {
        $old = $this->fall(changes: ['occurred_at' => $this->eventTime->subDays(200)]);
        $ack = $this->fall('acknowledged', changes: ['occurred_at' => $this->eventTime->subDays(100)]);
        $this->fall('resolved', changes: ['occurred_at' => $this->eventTime->subDays(150)]);
        $this->fall('false_alarm');
        $this->get('/perawat')->assertForbidden();
        $this->app['session']->driver()->flush();
        $this->actingAs($this->account('perawat'));
        $props = $this->get('/perawat?range=24h')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $props['stats']['fall_detections']);
        $this->assertSame(['pending' => 1, 'acknowledged' => 1], $props['open_incidents']);
        $this->assertSame($old->id, $props['pending_preview'][0]['id']);
        $list = $this->listing([], '/monitoring/open-incidents');
        $this->assertSame([$old->id, $ack->id], array_column($list['records']['data'], 'id'));
        $this->assertSame(1, $this->listing(['status' => 'acknowledged'], '/monitoring/open-incidents')['records']['total']);
    }

    public function test_event_envelope_is_not_mistaken_for_missing_child_or_wrong_type(): void
    {
        $this->event('gait', withChild: false);
        $fall = $this->event('fall', withChild: false);
        $fall->gaitObservation()->create(['category' => 'walking_normal']);
        $props = $this->get('/admin')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $props['stats']['fall_detections']);
        $this->assertSame(0, $props['stats']['gait_observations']);
        $this->assertSame(0, $props['stats']['pending']);
        foreach ($props['recent'] as $row) {
            $this->assertNull($row['gait']);
            $this->assertNull($row['fall']);
            $this->assertSame('unknown', $row['identity']['state']);
        }
    }

    public function test_each_dashboard_response_uses_one_select_for_all_monitoring_data(): void
    {
        $this->fall();
        DB::enableQueryLog();
        $this->get('/admin')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $selects = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'WITH clock')));
        $this->assertCount(1, $selects);
        $this->assertStringContainsString('open_counts AS', $selects[0]);
        $this->assertStringContainsString('pending_preview AS', $selects[0]);
        $this->assertStringNotContainsString('FOR UPDATE', $selects[0]);
        $this->assertStringNotContainsString('source_reference', $selects[0]);
        $this->assertStringNotContainsString('metadata', $selects[0]);
    }
}
