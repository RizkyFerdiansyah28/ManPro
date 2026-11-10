<?php

namespace Tests\Feature\Incidents;

use App\Services\CameraOperatingPeriodService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IncidentFeatureTestCase;

class IncidentMigrationTest extends IncidentFeatureTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_10_000006_add_incident_actions_and_precision.php');
    }

    public function test_additive_migration_preserves_legacy_rows_constraints_and_migration_records(): void
    {
        DB::table('fall_incidents')->update(['created_at' => DB::raw("date_trunc('second', created_at)"),
            'updated_at' => DB::raw("date_trunc('second', updated_at)")]);
        $rows = DB::table('fall_incidents')->orderBy('id')->get()->all();
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        $constraints = DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint
            WHERE conrelid='fall_incidents'::regclass ORDER BY conname");
        $this->migration()->down();
        $this->migration()->up();
        $this->assertEquals($rows, DB::table('fall_incidents')->orderBy('id')->get()->all());
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertEquals($constraints, DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint
            WHERE conrelid='fall_incidents'::regclass ORDER BY conname"));
        $precisions = DB::select("SELECT datetime_precision FROM information_schema.columns
            WHERE table_name='fall_incidents' AND data_type='timestamp with time zone' ORDER BY column_name");
        $this->assertSame([6, 6, 6, 6, 6], array_column($precisions, 'datetime_precision'));
        $this->assertDatabaseCount('fall_incident_actions', 0);
        $this->assertSame(12, DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'fall_incident_actions')->count());
        $this->assertSame('uuid', DB::selectOne("SELECT data_type FROM information_schema.columns
            WHERE table_schema='public' AND table_name='fall_incident_actions' AND column_name='id'")->data_type);
    }

    public static function downGuards(): array
    {
        return [['journal'], ['precision']];
    }

    #[DataProvider('downGuards')]
    public function test_down_guard_preserves_existing_journal_or_fractional_history(string $case): void
    {
        if ($case === 'journal') {
            $this->perform('acknowledge');
        } else {
            $this->incident->created_at = CameraOperatingPeriodService::serverTime()->setMicrosecond(123456);
            $this->incident->save();
        }
        $before = $this->monitoringSnapshot();
        $journal = DB::table('fall_incident_actions')->get()->all();
        try {
            DB::transaction(fn () => $this->migration()->down());
            $this->fail('Rollback yang menghilangkan histori harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Rollback ditolak karena jurnal atau presisi mikrodetik insiden harus dipertahankan. Gunakan perubahan maju.', $exception->getMessage());
        }
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->assertEquals($journal, DB::table('fall_incident_actions')->get()->all());
    }

    public static function invalidJournal(): array
    {
        return [
            ['sequence', 0, '23514', 'incident_actions_sequence_check'],
            ['action', 'reopen', '23514', 'incident_actions_transition_check'],
            ['to_status', 'resolved', '23514', 'incident_actions_transition_check'],
            ['request_fingerprint', 'invalid', '23514', 'incident_actions_fingerprint_check'],
            ['notes', str_repeat('a', 2001), '23514', 'incident_actions_notes_check'],
            ['actor_id', '00000000-0000-4000-8000-000000000001', '23503', 'fall_incident_actions_actor_id_foreign'],
        ];
    }

    #[DataProvider('invalidJournal')]
    public function test_journal_constraints_reject_exact_invalid_field_on_fresh_valid_fixture(string $field, mixed $value, string $state, string $constraint): void
    {
        $valid = ['id' => (string) Str::uuid(), 'fall_incident_id' => $this->incident->id, 'actor_id' => $this->nurse->id,
            'request_id' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'acknowledge',
            'from_status' => 'pending', 'to_status' => 'acknowledged', 'notes' => 'Catatan fixture valid',
            'request_fingerprint' => str_repeat('a', 64), 'acted_at' => CameraOperatingPeriodService::serverTime()];
        // Buktikan baseline valid, kemudian rollback savepoint agar kasus negatif terisolasi.
        DB::beginTransaction();
        DB::table('fall_incident_actions')->insert($valid);
        DB::rollBack();
        try {
            DB::transaction(fn () => DB::table('fall_incident_actions')->insert([...$valid, $field => $value]));
            $this->fail('Constraint harus menolak field invalid.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            $this->assertStringContainsString('"'.$constraint.'"', $exception->errorInfo[2]);
        }
        $this->assertDatabaseCount('fall_incident_actions', 0);
    }

    public function test_journal_model_is_append_only_and_microseconds_are_preserved(): void
    {
        $this->perform('acknowledge');
        $entry = $this->incident->actions()->sole();
        $this->assertTrue(Str::isUuid($entry->id));
        $time = CameraOperatingPeriodService::serverTime()->setMicrosecond(123456);
        DB::table('fall_incidents')->where('id', $this->incident->id)->update(['acknowledged_at' => $time->format('Y-m-d H:i:s.uP')]);
        $this->assertSame('123456', $this->incident->fresh()->acknowledged_at->format('u'));
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $entry->fresh()->update(['notes' => 'Dilarang']) : $entry->fresh()->delete();
                $this->fail('Jurnal aplikasi harus append-only.');
            } catch (LogicException $exception) {
                $this->assertSame(LogicException::class, $exception::class);
            }
        }
        $this->assertDatabaseCount('fall_incident_actions', 1);
    }
}
