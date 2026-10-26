<?php

namespace Tests\Feature\AiGateway;

use App\Models\AiDetection;
use App\Models\ElderlyStay;
use App\Models\ServiceEnrollment;
use App\Services\CameraService;
use App\Support\CameraVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AiGatewayFeatureTestCase;

class AiIngestionTest extends AiGatewayFeatureTestCase
{
    public function test_fall_is_pending_unassigned_private_and_preserves_other_records(): void
    {
        $before = $this->historySnapshot();
        unset($before['ai_detections'], $before['fall_incidents']);
        $periodBefore = $this->period->getAttributes();
        $response = $this->sendEvent($this->payload())->assertCreated()->assertExactJson([
            'accepted' => true, 'duplicate' => false, 'detection_id' => AiDetection::sole()->id,
        ])->assertHeader('Cache-Control', 'no-store, private')->assertDontSee(self::SECRET);
        $event = AiDetection::sole();
        $incident = $event->fallIncident()->sole();
        $this->assertSame('pending', $incident->status);
        foreach (['elderly_id', 'responding_caregiver_id', 'acknowledged_at', 'verified_by', 'verified_at', 'resolved_at', 'notes'] as $field) {
            $this->assertNull($incident->getAttribute($field));
        }
        $this->assertSame($this->eventTime->format('U.u'), $event->occurred_at->format('U.u'));
        $this->assertSame($this->period->id, $event->camera_period_id);
        $this->assertLessThanOrEqual(1024, strlen($event->getRawOriginal('metadata')));
        $this->assertArrayNotHasKey('metadata', $event->toArray());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('gait_observations', 0);
        $after = $this->historySnapshot();
        unset($after['ai_detections'], $after['fall_incidents']);
        $this->assertSame($before, $after);
        $this->assertSame($periodBefore, $this->period->fresh()->getAttributes());
        $this->actingAs($this->admin)->get('/admin/cameras')->assertOk()->assertDontSee(self::SECRET)->assertDontSee('payload_hash');
        $response->assertDontSee('elderly_id')->assertDontSee('room_id')->assertDontSee('source_reference');
    }

    public function test_retry_is_semantic_immutable_and_does_not_reset_human_incident_state(): void
    {
        $payload = $this->payload();
        $this->sendEvent($payload)->assertCreated();
        $event = AiDetection::sole();
        $caregiver = $this->account('perawat');
        $event->fallIncident()->update(['status' => 'acknowledged', 'responding_caregiver_id' => $caregiver->id, 'acknowledged_at' => now()]);
        $before = $this->historySnapshot();
        $payload['occurred_at'] = $this->eventTime->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:s.uP');
        $payload['confidence'] = '0.85';
        $this->sendEvent(array_reverse($payload, true))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($before, $this->historySnapshot());
        $this->sendEvent([...$payload, 'confidence' => '0.9'])->assertConflict()->assertJsonPath('error.code', 'event_conflict');
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_duplicate_remains_valid_after_camera_deactivation_and_event_age_limit(): void
    {
        $payload = $this->payload();
        $this->sendEvent($payload)->assertCreated();
        app(CameraService::class)->setActive($this->admin, $this->testCamera, CameraVersion::of($this->testCamera->fresh()), false);
        config(['ai_gateway.max_event_age_seconds' => 1]);
        $this->sendEvent($payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('ai_detections', 1);
    }

    public function test_legacy_external_identity_is_never_taken_over(): void
    {
        $payload = $this->payload();
        $event = AiDetection::create(['camera_id' => $this->testCamera->id, 'event_type' => 'fall',
            'external_event_id' => 'v1:'.self::SOURCE_ID.':'.$payload['external_event_id'], 'occurred_at' => $this->eventTime]);
        $before = $event->fresh()->getAttributes();
        $this->sendEvent($payload)->assertConflict();
        $this->assertSame($before, $event->fresh()->getAttributes());
        $this->assertDatabaseCount('fall_incidents', 0);
    }

    public function test_child_failure_rolls_back_entire_event_and_fresh_retry_succeeds(): void
    {
        $payload = $this->payload();
        DB::statement('ALTER TABLE fall_incidents ADD CONSTRAINT fixture_reject_child CHECK (false) NOT VALID');
        $this->sendEvent($payload)->assertServiceUnavailable()->assertDontSee('SQLSTATE')->assertDontSee('fixture_reject_child');
        $this->assertDatabaseCount('ai_detections', 0);
        $this->assertDatabaseCount('fall_incidents', 0);
        DB::statement('ALTER TABLE fall_incidents DROP CONSTRAINT fixture_reject_child');
        $this->sendEvent($payload)->assertCreated();
        $this->assertDatabaseCount('ai_detections', 1);
        $this->assertDatabaseCount('fall_incidents', 1);
    }

    public function test_old_closed_period_retains_room_after_camera_move_and_new_generation(): void
    {
        app(CameraService::class)->setActive($this->admin, $this->testCamera, CameraVersion::of($this->testCamera->fresh()), false);
        $destination = $this->room($this->shared, ['code' => 'NEXT']);
        app(CameraService::class)->update($this->admin, $this->testCamera, $this->cameraUpdate($this->testCamera, ['room_id' => $destination->id, 'confirm_reassignment' => true]));
        app(CameraService::class)->setActive($this->admin, $this->testCamera->fresh(), CameraVersion::of($this->testCamera->fresh()), true);
        $newPeriod = $this->testCamera->operatingPeriods()->whereNull('closed_at')->sole();
        $this->assertNotSame($this->period->id, $newPeriod->id);
        $this->sendEvent($this->payload())->assertCreated();
        $event = AiDetection::sole();
        $this->assertSame($this->period->room_id, $event->cameraPeriod->room_id);
        $this->assertSame($destination->id, $event->camera->room_id);
        $this->assertDatabaseCount('camera_operating_periods', 2);
        $this->sendEvent($this->payload(changes: ['camera_period_id' => $newPeriod->id]))->assertUnprocessable();
        $closedTime = $this->period->fresh()->closed_at;
        $this->sendEvent($this->payload(changes: ['occurred_at' => $closedTime->format('Y-m-d\TH:i:s.u\Z')]))->assertUnprocessable();
    }

    public function test_unpaid_invoice_does_not_restrict_safety_ingestion_or_change_billing(): void
    {
        $resident = $this->resident();
        $enrollment = ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $this->shared->id,
            'monthly_price' => '123.45', 'started_at' => $this->eventTime->subYear()->startOfMonth()]);
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $this->testCamera->room_id, 'started_at' => $enrollment->started_at]);
        $billingMonth = $this->eventTime->subMonthsNoOverflow(2)->startOfMonth();
        $dueDate = $billingMonth->endOfMonth();
        $invoice = $enrollment->invoices()->create(['elderly_id' => $resident->id, 'number' => 'AI-UNPAID-FIXTURE', 'billing_month' => $billingMonth->toDateString(),
            'amount' => '123.45', 'due_date' => $dueDate->toDateString(), 'grace_period_ends_on' => $dueDate->addDays(3)->toDateString()]);
        $this->assertTrue($enrollment->started_at->lessThan($billingMonth));
        $this->assertTrue($invoice->grace_period_ends_on->lessThan($this->eventTime));
        $before = $invoice->fresh()->getAttributes();
        $classBefore = $this->shared->fresh()->getAttributes();
        $this->sendEvent($this->payload())->assertCreated();
        $this->sendEvent($this->payload('gait'))->assertCreated();
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($classBefore, $this->shared->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_manifest_is_read_only_scoped_and_contains_no_source_or_resident_data(): void
    {
        $before = DB::table('camera_operating_periods')->get()->all();
        $this->assertSame(0, Artisan::call('silansia:ai-gateway-manifest', ['--source' => self::SOURCE_ID, '--no-ansi' => true]));
        $output = Artisan::output();
        $manifest = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(['source_id', 'periods'], array_keys($manifest));
        $this->assertSame(self::SOURCE_ID, $manifest['source_id']);
        $this->assertCount(1, $manifest['periods']);
        $this->assertSame(['camera_id', 'camera_period_id', 'opened_at'], array_keys($manifest['periods'][0]));
        $this->assertSame($this->testCamera->id, $manifest['periods'][0]['camera_id']);
        $this->assertSame($this->period->id, $manifest['periods'][0]['camera_period_id']);
        foreach ([self::SECRET, 'source_reference', 'elderly_id', 'room_id'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertSame(serialize($before), serialize(DB::table('camera_operating_periods')->get()->all()));
        $this->artisan('silansia:ai-gateway-manifest', ['--source' => (string) Str::uuid()])->assertFailed();
    }
}
