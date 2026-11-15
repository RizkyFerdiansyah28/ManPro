<?php

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\User;
use App\Services\ResidentService;
use Illuminate\Support\Facades\DB;

abstract class FinancialOwnershipFeatureTestCase extends PostgresFeatureTestCase
{
    private array $faults = [];

    private ?string $financialBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        DB::commit();
        config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only-approval']);
        $this->financialBefore = $this->financialSnapshot();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($this->faults !== []) {
            PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        }
        foreach (array_reverse($this->faults) as [$table, $name]) {
            DB::statement("DROP TRIGGER {$name} ON {$table}");
            DB::statement("DROP FUNCTION public.{$name}()");
        }
        if ($this->financialBefore !== null) {
            $this->assertSame($this->financialBefore, $this->financialSnapshot(), 'Writer ledger tidak boleh mengubah invoice/payment/revision/request billing.');
        }
        parent::tearDown();
    }

    private function financialSnapshot(): string
    {
        $rows = [];
        foreach (['invoices', 'payments', 'invoice_revisions', 'billing_requests'] as $table) {
            $rows[$table] = DB::selectOne('SELECT count(*)::int AS count, md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text), \'[]\'::jsonb)::text) AS fingerprint FROM public."'.$table.'" t');
        }

        return hash('sha256', serialize($rows));
    }

    protected function people(): array
    {
        return ['admin' => $this->account(), 'old' => $this->account('keluarga'), 'next' => $this->account('keluarga'), 'other' => $this->account('keluarga')];
    }

    protected function payload(User $family, array $changes = []): array
    {
        return [...['name' => 'Ledger fixture '.bin2hex(random_bytes(8)), 'birth_date' => '1950-01-01', 'family_id' => $family->id], ...$changes];
    }

    protected function resident(array $people): Elderly
    {
        return app(ResidentService::class)->create($people['admin'], $this->payload($people['old']));
    }

    protected function transfer(User $admin, Elderly $resident, User $family, ?string $expected = null): void
    {
        app(ResidentService::class)->reassignFamily($admin, $resident, ['family_id' => $family->id,
            'current_family_id' => $expected ?? $resident->family_id, 'confirm_reassignment' => true]);
    }

    protected function snapshot(): string
    {
        $rows = [];
        foreach (['elderlies', 'financial_ownership_periods', 'billing_actions', 'invoices', 'payments'] as $table) {
            $rows[$table] = DB::selectOne('SELECT count(*)::int AS count, md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text), \'[]\'::jsonb)::text) AS fingerprint FROM public."'.$table.'" t');
        }

        return hash('sha256', serialize($rows));
    }

    // Test-only fault injection: tambahan trigger terpisah, tidak mengubah guard P9-1B.
    protected function fault(string $stage, ?string $residentName = null): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        [$table, $event, $when] = match ($stage) {
            'resident' => ['elderlies', 'INSERT', ''],
            'root' => ['financial_ownership_periods', 'INSERT', "WHEN (NEW.source = 'resident_create')"],
            'close' => ['financial_ownership_periods', 'UPDATE', 'WHEN (OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL)'],
            'successor' => ['financial_ownership_periods', 'INSERT', "WHEN (NEW.source = 'family_transfer')"],
            'family' => ['elderlies', 'UPDATE', 'WHEN (NEW.family_id IS DISTINCT FROM OLD.family_id)'],
            'action', 'commit' => ['billing_actions', 'INSERT', "WHEN (NEW.action = 'ownership_opened')"],
        };
        $name = 'test_ledger_fault_'.bin2hex(random_bytes(6));
        $condition = $residentName === null ? 'TRUE' : 'EXISTS (SELECT 1 FROM public.elderlies WHERE id=NEW.elderly_id AND name='.DB::getPdo()->quote($residentName).')';
        DB::unprepared("CREATE FUNCTION public.{$name}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
            IF {$condition} THEN RAISE EXCEPTION USING ERRCODE='23514',CONSTRAINT='test_ledger_fault',MESSAGE='test_ledger_fault'; END IF;
            RETURN NEW; END \$f\$");
        $timing = $stage === 'commit' ? 'CONSTRAINT' : '';
        $before = $stage === 'commit' ? 'AFTER' : 'BEFORE';
        $deferred = $stage === 'commit' ? 'DEFERRABLE INITIALLY DEFERRED' : '';
        DB::statement("CREATE {$timing} TRIGGER {$name} {$before} {$event} ON {$table} {$deferred} FOR EACH ROW {$when} EXECUTE FUNCTION public.{$name}()");
        $this->faults[] = [$table, $name];
    }
}
