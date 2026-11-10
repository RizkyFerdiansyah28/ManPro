<?php

namespace Tests\Feature\Notifications;

use App\Support\StaffNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NotificationFeatureTestCase;

class NotificationMigrationTest extends NotificationFeatureTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_10_000007_harden_staff_notifications.php');
    }

    private function attributes(): array
    {
        return ['id' => (string) Str::uuid(), 'type' => StaffNotification::KIND, 'notifiable_type' => StaffNotification::MORPH,
            'notifiable_id' => $this->nurse->id, 'fall_incident_id' => $this->incident->id,
            'data' => json_encode(['schema_version' => 1, 'message' => StaffNotification::MESSAGE]),
            'read_at' => null, 'created_at' => '2026-11-10 00:00:00+00', 'updated_at' => '2026-11-10 00:00:00+00'];
    }

    public function test_additive_hardening_preserves_legacy_data_columns_and_migration_records(): void
    {
        $this->notice(changes: ['type' => 'fixture.legacy', 'fall_incident_id' => null, 'data' => ['private' => 'LEGACY-DATA']]);
        $this->notice(changes: ['type' => 'fixture.legacy', 'fall_incident_id' => null, 'data' => ['private' => 'LEGACY-DATA']]);
        $before = $this->snapshot();
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        $columns = DB::select("SELECT column_name,data_type,is_nullable,datetime_precision FROM information_schema.columns
            WHERE table_schema='public' AND table_name='notifications' ORDER BY ordinal_position");
        DB::transaction(function () {
            $this->migration()->down();
            $this->migration()->up();
        });
        $this->assertSame($before, $this->snapshot());
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertEquals($columns, DB::select("SELECT column_name,data_type,is_nullable,datetime_precision FROM information_schema.columns
            WHERE table_schema='public' AND table_name='notifications' ORDER BY ordinal_position"));
        $this->assertCount(9, $columns);
    }

    public static function invalidRecords(): array
    {
        return [['missing_fk', '23514', 'notifications_staff_incident_check'],
            ['duplicate', '23505', 'notifications_staff_incident_unique'],
            ['morph', '23514', 'notifications_check_0'],
            ['user', '23503', 'notifications_notifiable_id_foreign'],
            ['incident', '23503', 'notifications_fall_incident_id_foreign']];
    }

    #[DataProvider('invalidRecords')]
    public function test_each_constraint_rejection_starts_from_proven_valid_state(string $case, string $state, string $constraint): void
    {
        $valid = $this->attributes();
        DB::beginTransaction();
        DB::table('notifications')->insert($valid);
        DB::rollBack();
        if ($case === 'duplicate') {
            DB::table('notifications')->insert($valid);
        }
        $bad = [...$valid, 'id' => (string) Str::uuid()];
        match ($case) {
            'missing_fk' => $bad['fall_incident_id'] = null,
            'morph' => $bad['notifiable_type'] = 'OtherModel',
            'user' => $bad['notifiable_id'] = (string) Str::uuid(),
            'incident' => $bad['fall_incident_id'] = (string) Str::uuid(),
            default => null,
        };
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => DB::table('notifications')->insert($bad));
            $this->fail('Constraint harus menolak tepat kasus invalid.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            $this->assertStringContainsString('"'.$constraint.'"', $exception->errorInfo[2]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function unsafeUp(): array
    {
        return [['missing_fk'], ['duplicates']];
    }

    #[DataProvider('unsafeUp')]
    public function test_preflight_refuses_unsafe_existing_rows_without_rewriting_them(string $case): void
    {
        DB::beginTransaction();
        try {
            $this->migration()->down();
            $data = $this->attributes();
            if ($case === 'missing_fk') {
                $data['fall_incident_id'] = null;
            }
            DB::table('notifications')->insert($data);
            if ($case === 'duplicates') {
                DB::table('notifications')->insert([...$data, 'id' => (string) Str::uuid()]);
            }
            $before = $this->snapshot();
            try {
                DB::transaction(fn () => $this->migration()->up());
                $this->fail('Preflight harus menolak existing invalid.');
            } catch (RuntimeException $exception) {
                $this->assertSame(RuntimeException::class, $exception::class);
                $this->assertSame('Hardening notifikasi ditolak: terdapat notifikasi staf tanpa insiden atau duplikat. Data tidak diubah.', $exception->getMessage());
            }
            $this->assertSame($before, $this->snapshot());
        } finally {
            DB::rollBack();
        }
    }

    public function test_down_guard_keeps_notification_history_and_dedup_index(): void
    {
        $this->notice();
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => $this->migration()->down());
            $this->fail('Down harus mempertahankan hardening existing.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Rollback ditolak karena notifikasi staf masih memerlukan pengaman deduplikasi. Gunakan perubahan maju.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertNotNull(DB::scalar("SELECT to_regclass('public.notifications_staff_incident_unique')"));
    }
}
