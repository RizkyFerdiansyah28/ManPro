<?php

namespace Tests\Feature\Notifications;

use App\Exceptions\NotificationDeliveryException;
use App\Jobs\ReconcileFallNotifications;
use App\Models\FallIncident;
use App\Models\Invoice;
use App\Models\ServiceEnrollment;
use App\Services\AccountService;
use App\Services\AiIngestionService;
use App\Services\FallSafetyNotificationService;
use App\Services\NotificationService;
use App\Support\AiGateway\EventPayload;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NotificationFeatureTestCase;

class NotificationDeliveryTest extends NotificationFeatureTestCase
{
    private function ingest(): array
    {
        return app(AiIngestionService::class)->ingest(new EventPayload([
            'contract_version' => '1', 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall',
            'camera_id' => $this->camera->id, 'camera_period_id' => $this->period->id,
            'occurred_at' => $this->eventTime->format('Y-m-d\TH:i:s.u\Z'), 'confidence' => '0.85000',
        ]), ['id' => (string) Str::uuid(), 'key_id' => (string) Str::uuid(),
            'camera_ids' => [$this->camera->id], 'event_types' => ['fall']]);
    }

    public function test_dispatch_waits_for_outer_commit_and_is_discarded_on_rollback(): void
    {
        Bus::fake([ReconcileFallNotifications::class]);
        $this->guard();
        DB::beginTransaction();
        $accepted = $this->ingest();
        Bus::assertNothingDispatched();
        DB::commit();
        $incident = FallIncident::where('ai_detection_id', $accepted['detection_id'])->sole();
        Bus::assertDispatched(ReconcileFallNotifications::class, fn ($job) => $job->incidentId === $incident->id);
        Bus::assertDispatchedTimes(ReconcileFallNotifications::class, 1);
        DB::beginTransaction();
        $rolled = $this->ingest();
        DB::rollBack();
        Bus::assertDispatchedTimes(ReconcileFallNotifications::class, 1);
        $this->assertDatabaseMissing('ai_detections', ['id' => $rolled['detection_id']]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_enqueue_failure_and_logger_failure_do_not_change_committed_ingestion_result(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE-QUEUE-ERROR'));
        Log::shouldReceive('warning')->once()->with('notifications_dispatch_failed')->andThrow(new RuntimeException('PRIVATE-LOGGER-ERROR'));
        $accepted = $this->ingest();
        $this->assertTrue($accepted['accepted']);
        $incident = FallIncident::where('ai_detection_id', $accepted['detection_id'])->sole();
        $this->assertSame('pending', $incident->status);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(2, $this->deliver($incident));
        $this->assertSame('pending', $incident->fresh()->status);
    }

    public function test_fanout_is_independent_atomic_sanitized_and_idempotent(): void
    {
        $before = $this->snapshot();
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT fixture_notification_failure CHECK (type <> 'silansia.fall_pending.v1') NOT VALID");
        try {
            try {
                $this->deliver();
                $this->fail('Insert harus ditolak fixture constraint.');
            } catch (NotificationDeliveryException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
                $this->assertStringNotContainsString('fixture_notification_failure', $exception->getMessage());
            }
        } finally {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT fixture_notification_failure');
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, $this->deliver());
        $row = DB::table('notifications')->where('notifiable_id', $this->nurse->id)->sole();
        app(NotificationService::class)->read($this->nurse, $row->id);
        $afterRead = $this->snapshot();
        $this->assertSame(0, $this->deliver());
        $this->assertSame($afterRead, $this->snapshot());
        $owners = DB::table('notifications')->pluck('notifiable_id')->all();
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->nurse->id], $owners);
        foreach ($before as $table => $rows) {
            if ($table !== 'notifications') {
                $this->assertSame($rows, $afterRead[$table], $table);
            }
        }
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $this->deliver();
        } finally {
            DB::rollBack();
        }
    }

    public function test_new_and_reactivated_staff_receive_pending_without_resetting_read_history(): void
    {
        $new = $this->account('perawat', false);
        $inactive = $this->account('admin', false);
        $this->assertSame(2, $this->deliver());
        $read = DB::table('notifications')->where('notifiable_id', $this->nurse->id)->sole();
        app(NotificationService::class)->read($this->nurse, $read->id);
        $readAt = DB::table('notifications')->where('id', $read->id)->value('read_at');
        app(AccountService::class)->setActive($this->admin, $new, true);
        $this->assertSame(['processed' => 1, 'inserted' => 1, 'failed' => 0], app(FallSafetyNotificationService::class)->reconcile());
        app(AccountService::class)->setActive($this->admin, $this->nurse, false);
        $next = $this->fall();
        $this->assertSame(2, $this->deliver($next));
        app(AccountService::class)->setActive($this->admin, $this->nurse->fresh(), true);
        $this->assertSame(1, app(FallSafetyNotificationService::class)->reconcile()['inserted']);
        $this->assertSame($readAt, DB::table('notifications')->where('id', $read->id)->value('read_at'));
        $this->assertSame(0, DB::table('notifications')->whereIn('notifiable_id', [$this->family->id, $inactive->id])->count());
    }

    public static function handledStates(): array
    {
        return [['acknowledged', false], ['acknowledged', true], ['resolved', true], ['false_alarm', false]];
    }

    #[DataProvider('handledStates')]
    public function test_handled_source_is_skipped_and_does_not_create_family_or_transition_notifications(string $state, bool $verified): void
    {
        $changes = ['status' => $state, 'responding_caregiver_id' => $this->nurse->id, 'acknowledged_at' => $this->eventTime->addMinute()];
        if ($verified) {
            $changes += ['verified_by' => $this->nurse->id, 'verified_at' => $this->eventTime->addMinutes(2)];
        }
        if (in_array($state, ['resolved', 'false_alarm'])) {
            $changes['resolved_at'] = $this->eventTime->addMinutes(3);
        }
        $this->incident->update($changes);
        $before = $this->snapshot();
        $this->assertSame(0, $this->deliver());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_legacy_unknown_inactive_camera_benefits_and_overdue_billing_do_not_gate_staff(): void
    {
        $resident = $this->family->elderlies()->create(['name' => 'PRIVATE-RESIDENT', 'birth_date' => '1950-01-01']);
        $class = $this->room->serviceClass;
        $enrollment = ServiceEnrollment::create(['elderly_id' => $resident->id, 'service_class_id' => $class->id,
            'monthly_price' => '123.45', 'started_at' => now()->subYear()]);
        $month = now()->subMonths(2)->startOfMonth();
        Invoice::create(['number' => 'NTF-OVERDUE', 'elderly_id' => $resident->id, 'service_enrollment_id' => $enrollment->id,
            'billing_month' => $month->toDateString(), 'amount' => '123.45', 'due_date' => $month->copy()->addDays(4)->toDateString(),
            'grace_period_ends_on' => $month->copy()->addDays(7)->toDateString()]);
        $class->update(['is_active' => false, 'instant_verified_emergency' => false, 'monthly_gait_report' => false]);
        $this->room->update(['is_active' => false]);
        $this->incident->aiDetection->update(['camera_period_id' => null]);
        $before = $this->snapshot();
        $this->assertSame(2, $this->deliver());
        $after = $this->snapshot();
        foreach ($before as $table => $rows) {
            if ($table !== 'notifications') {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $this->assertNull($this->incident->fresh()->elderly_id);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->family->id)->count());
    }

    public function test_gait_or_conflicting_child_is_not_a_notification_source(): void
    {
        $this->incident->aiDetection->update(['event_type' => 'gait']);
        $this->assertSame(0, $this->deliver());
        $this->incident->aiDetection->update(['event_type' => 'fall']);
        $this->incident->aiDetection->gaitObservation()->create(['category' => 'walking_abnormal']);
        $this->assertSame(0, $this->deliver());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_reconciliation_does_not_starve_missing_recipients_behind_already_delivered_pending(): void
    {
        $this->deliver();
        $next = $this->fall();
        $result = app(FallSafetyNotificationService::class)->reconcile(1);
        $this->assertSame(['processed' => 1, 'inserted' => 2, 'failed' => 0], $result);
        $this->assertSame(2, DB::table('notifications')->where('fall_incident_id', $next->id)->count());
        $this->assertSame(['processed' => 0, 'inserted' => 0, 'failed' => 0], app(FallSafetyNotificationService::class)->reconcile(1));
    }
}
