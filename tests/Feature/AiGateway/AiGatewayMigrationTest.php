<?php

namespace Tests\Feature\AiGateway;

use App\Models\AiDetection;
use App\Models\CameraOperatingPeriod;
use App\Services\CameraService;
use App\Support\CameraVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AiGatewayFeatureTestCase;

class AiGatewayMigrationTest extends AiGatewayFeatureTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_24_000005_link_ai_detections_to_camera_periods.php');
    }

    private function rejects(callable $operation, string $state, ?string $constraint = null): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database wajib menolak fixture invalid.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $error->errorInfo[2]);
            }
        }
    }

    public function test_additive_upgrade_preserves_legacy_rows_defaults_and_migration_records(): void
    {
        $migration = $this->migration();
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        $migration->down();
        $legacy = AiDetection::create(['camera_id' => $this->testCamera->id, 'external_event_id' => (string) Str::uuid(),
            'event_type' => 'fall', 'occurred_at' => $this->eventTime->startOfSecond(), 'received_at' => $this->eventTime->startOfSecond()]);
        $before = (array) DB::table('ai_detections')->where('id', $legacy->id)->sole();
        $periodBefore = $this->period->getAttributes();
        $migration->up();
        $after = (array) DB::table('ai_detections')->where('id', $legacy->id)->sole();
        $this->assertNull($after['camera_period_id']);
        unset($after['camera_period_id']);
        $this->assertSame($before, $after);
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertSame($periodBefore, $this->period->fresh()->getAttributes());
        $columns = DB::select("SELECT column_name,datetime_precision,column_default FROM information_schema.columns WHERE table_name='ai_detections' AND column_name IN ('occurred_at','received_at') ORDER BY column_name");
        $this->assertSame([6, 6], array_column($columns, 'datetime_precision'));
        $this->assertSame('CURRENT_TIMESTAMP', $columns[1]->column_default);
    }

    public function test_period_migration_does_not_backfill_or_toggle_existing_camera(): void
    {
        $cameraBefore = $this->testCamera->getAttributes();
        $migration = $this->migration();
        $periodMigration = require database_path('migrations/2026_10_24_000004_create_camera_operating_periods.php');
        $migration->down();
        $periodMigration->down();
        $periodMigration->up();
        $migration->up();
        $this->assertDatabaseCount('camera_operating_periods', 0);
        $this->assertSame($cameraBefore, $this->testCamera->fresh()->getAttributes());
        app(CameraService::class)->setActive($this->admin, $this->testCamera, CameraVersion::of($this->testCamera->fresh()), true);
        $this->assertDatabaseCount('camera_operating_periods', 0); // No-op legacy tetap no-op.
        app(CameraService::class)->setActive($this->admin, $this->testCamera, CameraVersion::of($this->testCamera->fresh()), false);
        app(CameraService::class)->setActive($this->admin, $this->testCamera->fresh(), CameraVersion::of($this->testCamera->fresh()), true);
        $this->assertDatabaseCount('camera_operating_periods', 1);
    }

    public function test_composite_foreign_key_rejects_a_period_from_another_camera(): void
    {
        $camera = $this->camera($this->room($this->shared, ['code' => 'FK-OTHER']));
        $this->rejects(fn () => AiDetection::create(['camera_id' => $camera->id, 'camera_period_id' => $this->period->id,
            'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall', 'occurred_at' => $this->eventTime]),
            '23503', 'ai_detections_period_camera_foreign');
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_period_constraints_reject_overlap_open_and_invalid_time_without_corrupting_fixture(): void
    {
        $before = $this->period->getAttributes();
        $attrs = $this->period->only(['camera_id', 'room_id', 'service_class_id', 'room_type', 'room_capacity', 'source_type', 'opened_at']);
        $this->rejects(fn () => CameraOperatingPeriod::create($attrs), '23505', 'camera_periods_one_open');
        $this->rejects(fn () => $this->period->fresh()->update(['closed_at' => $this->period->opened_at]), '23514', 'camera_periods_time_check');
        $this->rejects(fn () => $this->period->fresh()->update(['room_type' => 'private', 'room_capacity' => 2]), '23514', 'camera_periods_room_check');
        $this->assertSame($before, $this->period->fresh()->getAttributes());
    }

    public static function rollbackCases(): array
    {
        return [['period'], ['precision']];
    }

    #[DataProvider('rollbackCases')]
    public function test_rollback_refuses_loss_of_period_or_fractional_history(string $case): void
    {
        if ($case === 'period') {
            $this->sendEvent($this->payload())->assertCreated();
        } else {
            AiDetection::create(['camera_id' => $this->testCamera->id, 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall',
                'occurred_at' => $this->eventTime->setMicrosecond(123456), 'received_at' => $this->eventTime->startOfSecond()]);
        }
        $before = $this->historySnapshot();
        try {
            DB::transaction(fn () => $this->migration()->down());
            $this->fail('Rollback berisiko harus ditolak.');
        } catch (RuntimeException $error) {
            $this->assertSame(RuntimeException::class, $error::class);
            $this->assertSame('Rollback ditolak karena deteksi memiliki periode atau presisi mikrodetik. Gunakan perubahan maju.', $error->getMessage());
        }
        $this->assertSame($before, $this->historySnapshot());
        $this->assertSame(6, DB::selectOne("SELECT datetime_precision FROM information_schema.columns WHERE table_name='ai_detections' AND column_name='occurred_at'")->datetime_precision);
    }

    public static function specialNumbers(): array
    {
        return [['NaN', '23514', 'ai_detections_check_1'], ['Infinity', '22003', null], ['-Infinity', '22003', null]];
    }

    #[DataProvider('specialNumbers')]
    public function test_database_confidence_rejects_special_numbers_exactly(string $value, string $state, ?string $constraint): void
    {
        $event = AiDetection::create(['camera_id' => $this->testCamera->id, 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall',
            'occurred_at' => $this->eventTime, 'confidence' => '0.85000']);
        $this->rejects(fn () => DB::table('ai_detections')->where('id', $event->id)->update(['confidence' => $value]), $state, $constraint);
        $this->assertSame('0.85000', $event->fresh()->confidence);
    }
}
