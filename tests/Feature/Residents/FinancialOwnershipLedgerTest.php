<?php

namespace Tests\Feature\Residents;

use App\Models\Elderly;
use App\Services\FinancialOwnershipLedgerService;
use App\Services\ResidentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FinancialOwnershipFeatureTestCase;

#[Group('financial-ownership-ledger')]
class FinancialOwnershipLedgerTest extends FinancialOwnershipFeatureTestCase
{
    public function test_create_and_transfer_commit_real_contiguous_prospective_history_without_billing(): void
    {
        $p = $this->people();
        $before = DB::scalar('SELECT clock_timestamp()::text');
        $resident = $this->resident($p);
        $root = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->sole();
        $this->assertSame('resident_create', $root->source);
        $this->assertNull($root->previous_period_id);
        $this->assertSame($root->effective_from, $root->recorded_at);
        $this->assertTrue(DB::scalar('SELECT ?::timestamptz >= ?::timestamptz', [$root->effective_from, $before]));
        $this->transfer($p['admin'], $resident, $p['next']);
        $old = DB::table('financial_ownership_periods')->where('id', $root->id)->sole();
        $leaf = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->whereNull('effective_to')->sole();
        $this->assertSame($root->id, $leaf->previous_period_id);
        $this->assertSame($old->effective_to, $leaf->effective_from);
        $this->assertSame($leaf->recorded_at, $leaf->effective_from);
        $this->assertSame('family_transfer', $leaf->source);
        $this->assertSame($p['next']->id, $resident->fresh()->family_id);
        $this->assertSame(3, DB::table('billing_actions')->whereIn('ownership_period_id', [$root->id, $leaf->id])->count());
        DB::select('SELECT public.billing_validate_ownership(?)', [$resident->id]);
        $this->assertSame(0, DB::table('invoices')->where('elderly_id', $resident->id)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function failures(): array
    {
        return [['resident', 'create'], ['root', 'create'], ['action', 'create'], ['commit', 'create'],
            ['close', 'transfer'], ['successor', 'transfer'], ['family', 'transfer'], ['action', 'transfer'], ['commit', 'transfer']];
    }

    #[DataProvider('failures')]
    public function test_actual_database_failure_rolls_back_all_resident_ledger_and_audit_changes(string $stage, string $operation): void
    {
        $p = $this->people();
        $resident = $operation === 'transfer' ? $this->resident($p) : null;
        $before = $this->snapshot();
        $this->fault($stage);
        try {
            $operation === 'create' ? $this->resident($p) : $this->transfer($p['admin'], $resident, $p['next']);
            $this->fail('Fault database tidak boleh menjadi sukses palsu.');
        } catch (PDOException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('test_ledger_fault', $exception->errorInfo[2]);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot());
    }

    public static function rejectedTransfers(): array
    {
        return [['stale'], ['same'], ['inactive'], ['unconfirmed'], ['missing-ledger'], ['missing-approval'], ['invalid-gate']];
    }

    #[DataProvider('rejectedTransfers')]
    public function test_invalid_transfer_never_closes_or_creates_period(string $case): void
    {
        $p = $this->people();
        if ($case === 'missing-ledger') {
            config(['financial_ownership.enabled' => false]);
        }
        $resident = $this->resident($p);
        config(['financial_ownership.enabled' => true]);
        $data = ['family_id' => $p['next']->id, 'current_family_id' => $p['old']->id, 'confirm_reassignment' => true];
        match ($case) {
            'stale' => $data['current_family_id'] = $p['other']->id,
            'same' => $data['family_id'] = $p['old']->id,
            'inactive' => $p['next']->forceFill(['is_active' => false])->save(),
            'unconfirmed' => $data['confirm_reassignment'] = false,
            'missing-approval' => config(['financial_ownership.cutover_reference' => null]),
            'invalid-gate' => config(['financial_ownership.enabled' => 'yes']),
            default => null,
        };
        $before = $this->snapshot();
        try {
            app(ResidentService::class)->reassignFamily($p['admin'], $resident, $data);
            $this->fail('Transfer harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty(array_intersect(array_keys($exception->errors()), ['family_id', 'confirm_reassignment', 'operation']));
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_gate_off_preserves_legacy_workflow_and_request_flags_cannot_activate_it(): void
    {
        $p = $this->people();
        config(['financial_ownership.enabled' => false, 'financial_ownership.cutover_reference' => null]);
        $data = $this->payload($p['old'], ['financial_ownership' => ['enabled' => true], 'effective_from' => '1900-01-01', 'source' => 'cutover_capture']);
        $this->actingAs($p['admin'])->post('/admin/residents', $data)->assertSessionHasNoErrors()->assertRedirect();
        $resident = Elderly::where('name', $data['name'])->sole();
        $this->transfer($p['admin'], $resident, $p['next']);
        $this->assertSame(0, DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->count());
        $this->assertFalse(config('financial_ownership.enabled'));
    }

    public function test_timestamp_uses_fresh_database_clock_even_when_php_clock_and_input_are_frozen(): void
    {
        $p = $this->people();
        CarbonImmutable::setTestNow('2001-01-01 00:00:00+00');
        try {
            $resident = app(ResidentService::class)->create($p['admin'], $this->payload($p['old'], ['effective_from' => '1900-01-01']));
            $period = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->sole();
            $this->assertTrue(DB::scalar("SELECT ?::timestamptz > '2020-01-01'::timestamptz", [$period->recorded_at]));
            $this->assertSame($period->effective_from, $period->recorded_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_gate_on_with_missing_migration_marker_fails_before_insert(): void
    {
        $p = $this->people();
        $before = $this->snapshot();
        DB::beginTransaction();
        try {
            DB::table('migrations')->where('migration', '2026_11_13_000009_add_billing_integrity_guards')->update(['migration' => 'owned_fixture_missing_guard_marker']);
            try {
                $this->resident($p);
                $this->fail('Readiness harus fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('operation', $exception->errors());
            }
            $this->assertSame($before, $this->snapshot());
        } finally {
            DB::rollBack();
        }
    }

    public function test_service_does_not_create_a_root_for_an_existing_uncaptured_resident(): void
    {
        $p = $this->people();
        config(['financial_ownership.enabled' => false]);
        $resident = $this->resident($p);
        config(['financial_ownership.enabled' => true]);
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => app(FinancialOwnershipLedgerService::class)->createForNewResident($p['admin'], $resident->fresh()));
            $this->fail('Tidak boleh implicit capture existing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_non_forward_clock_is_rejected_without_incrementing_or_fabricating_time(): void
    {
        $p = $this->people();
        $resident = $this->resident($p);
        $before = $this->snapshot();
        $this->app->instance(FinancialOwnershipLedgerService::class, new class extends FinancialOwnershipLedgerService
        {
            protected function serverInstant(?string $after = null): string
            {
                // Seam test untuk kondisi clock <= open.from; clock database asli tetap dibaca.
                return parent::serverInstant('2999-01-01 00:00:00+00');
            }
        });
        try {
            $this->transfer($p['admin'], $resident, $p['next']);
            $this->fail('Waktu tidak maju wajib membatalkan transaksi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_gate_on_requires_read_committed_and_inactive_previous_family_does_not_rewrite_history(): void
    {
        $p = $this->people();
        $before = $this->snapshot();
        try {
            DB::transaction(function () use ($p) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->resident($p);
            });
            $this->fail('Readiness harus menolak isolation yang tidak didukung.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
        $resident = $this->resident($p);
        $p['old']->forceFill(['is_active' => false])->save();
        $this->transfer($p['admin'], $resident, $p['next']);
        $this->assertSame($p['next']->id, $resident->fresh()->family_id);
        $this->assertSame($p['old']->id, DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->whereNull('previous_period_id')->value('family_id'));
    }

    public function test_unauthorized_actor_cannot_mutate_ledger_and_profile_update_preserves_it(): void
    {
        $p = $this->people();
        $resident = $this->resident($p);
        $before = $this->snapshot();
        foreach ([$p['old'], $this->account('perawat')] as $actor) {
            $this->app['session']->driver()->flush();
            $this->actingAs($actor)->post('/admin/residents/'.$resident->id.'/family', ['family_id' => $p['next']->id,
                'current_family_id' => $p['old']->id, 'confirm_reassignment' => true])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot());
        $period = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->sole();
        app(ResidentService::class)->update($p['admin'], $resident, ['name' => 'Updated fixture', 'birth_date' => '1949-01-01']);
        $this->assertEquals($period, DB::table('financial_ownership_periods')->where('id', $period->id)->sole());
    }
}
