<?php

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class InvoiceIssuanceFeatureTestCase extends PostgresFeatureTestCase
{
    use BillingIntegrityFixtures;

    private array $faultTriggers = [];

    private array $sequences = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::commit(); // Outer COMMIT nyata; immutable fixture tidak dibersihkan lewat DELETE.
        config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only-approval',
            'invoice_issuance.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->app && config('database.default') === 'silansia_testing') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->faultTriggers !== []) {
                PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
                foreach (array_reverse($this->faultTriggers) as [$table, $name]) {
                    DB::statement("DROP TRIGGER {$name} ON public.{$table}");
                    DB::statement("DROP FUNCTION public.{$name}()");
                }
                foreach ($this->sequences as $sequence) {
                    DB::statement("DROP SEQUENCE public.{$sequence}");
                }
            }
        }
        parent::tearDown();
    }

    protected function source(array $changes = []): array
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());

        return DB::transaction(function () use ($changes) {
            if (! DB::table('service_classes')->where('code', 'vip')->exists()) {
                (new ServiceClassSeeder)->run();
            }
            $admin = $this->account();
            $family = $this->account('keluarga');
            $next = $this->account('keluarga');
            $elderly = Elderly::create(['family_id' => $family->id, 'name' => 'Synthetic issuance fixture',
                'birth_date' => '1950-01-01', 'is_active' => $changes['active'] ?? true]);
            $enrollment = null;
            if (! ($changes['no_service'] ?? false)) {
                $enrollment = ServiceEnrollment::create(['elderly_id' => $elderly->id,
                    'service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
                    'monthly_price' => array_key_exists('price', $changes) ? $changes['price'] : '3100.00',
                    'started_at' => $changes['start'] ?? '2026-10-01 00:00:00+07', 'ended_at' => $changes['end'] ?? null]);
            }
            $period = null;
            if (! ($changes['no_ledger'] ?? false)) {
                $from = $changes['from'] ?? '2026-09-01 00:00:00.123456+07';
                $period = ['id' => (string) Str::uuid(), 'elderly_id' => $elderly->id, 'family_id' => $family->id,
                    'previous_period_id' => null, 'effective_from' => $from, 'effective_to' => null,
                    'recorded_by' => $admin->id, 'recorded_at' => $from, 'source' => 'cutover_capture'];
                // Waktu sintetis hanya fixture test owned, bukan capture/backfill aplikasi.
                DB::table('financial_ownership_periods')->insert($period);
                $this->billingOwnershipAction($period, 'ownership_captured', $admin->id, $from);
            }

            return compact('admin', 'family', 'next', 'elderly', 'enrollment', 'period');
        });
    }

    protected function sourceTransfer(array $s, string $at): void
    {
        DB::transaction(function () use ($s, $at) {
            DB::table('users')->whereIn('id', [$s['admin']->id, $s['family']->id, $s['next']->id])->orderBy('id')->lockForUpdate()->get();
            DB::table('elderlies')->where('id', $s['elderly']->id)->lockForUpdate()->first();
            DB::table('financial_ownership_periods')->where('elderly_id', $s['elderly']->id)->orderBy('id')->lockForUpdate()->get();
            DB::table('financial_ownership_periods')->where('id', $s['period']['id'])->update(['effective_to' => $at]);
            $this->billingOwnershipAction($s['period'], 'ownership_closed', $s['admin']->id, $at);
            $next = [...$s['period'], 'id' => (string) Str::uuid(), 'family_id' => $s['next']->id,
                'previous_period_id' => $s['period']['id'], 'effective_from' => $at, 'effective_to' => null, 'recorded_at' => $at, 'source' => 'family_transfer'];
            DB::table('financial_ownership_periods')->insert($next);
            DB::table('elderlies')->where('id', $s['elderly']->id)->update(['family_id' => $s['next']->id]);
            $this->billingOwnershipAction($next, 'ownership_opened', $s['admin']->id, $at);
        });
    }

    protected function command(array $s, ?string $requestId = null): InvoiceIssueCommand
    {
        $preview = app(InvoiceIssuanceService::class)->preview($s['admin']->id, $s['elderly']->id, '2026-10-01');

        return new InvoiceIssueCommand($s['admin']->id, $s['elderly']->id, '2026-10-01', $requestId ?? (string) Str::uuid(), $preview->confirmation);
    }

    protected function fingerprints(?array $tables = null): array
    {
        $tables ??= array_map(fn ($r) => $r->tablename, DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"));
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::selectOne('SELECT count(*)::int AS count,md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\'::jsonb)::text) AS hash FROM public."'.$table.'" t');
        }

        return $result;
    }

    protected function assertFailure(string $kind, callable $operation, ?string $state = null, ?string $rule = null): InvoiceIssuanceFailure
    {
        try {
            $operation();
            $this->fail('Operasi harus ditolak: '.$kind);
        } catch (InvoiceIssuanceFailure $failure) {
            $this->assertSame($kind, $failure->kind);
            $this->assertSame($state, $failure->sqlState);
            $this->assertSame($rule, $failure->rule);

            return $failure;
        }
    }

    protected function fault(string $stage): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        [$table, $when] = match ($stage) {
            'root' => ['invoices', 'WHEN (NEW.billing_schema_version = 1)'],
            'revision' => ['invoice_revisions', ''],
            'request' => ['billing_requests', "WHEN (NEW.operation = 'issue')"],
            'issued', 'deferred' => ['billing_actions', "WHEN (NEW.action = 'invoice_issued')"],
            'zero' => ['billing_actions', "WHEN (NEW.action = 'zero_charge')"],
        };
        $name = 'test_issue_fault_'.bin2hex(random_bytes(5));
        DB::unprepared("CREATE FUNCTION public.{$name}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
            RAISE EXCEPTION USING ERRCODE='23514',CONSTRAINT='test_issue_fault',MESSAGE='test_issue_fault'; RETURN NEW; END \$f\$");
        $constraint = $stage === 'deferred' ? 'CONSTRAINT' : '';
        $deferrable = $stage === 'deferred' ? 'DEFERRABLE INITIALLY DEFERRED' : '';
        DB::statement("CREATE {$constraint} TRIGGER {$name} AFTER INSERT ON public.{$table} {$deferrable} FOR EACH ROW {$when} EXECUTE FUNCTION public.{$name}()");
        $this->faultTriggers[] = [$table, $name];
    }

    protected function transientFault(bool $once): string
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $name = 'test_issue_retry_'.bin2hex(random_bytes(5));
        $sequence = $name.'_seq';
        $limit = $once ? 1 : 2;
        DB::statement("CREATE SEQUENCE public.{$sequence}");
        DB::unprepared("CREATE FUNCTION public.{$name}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
            IF nextval('public.{$sequence}') <= {$limit} THEN
                RAISE EXCEPTION USING ERRCODE='40P01',MESSAGE='test-only transient SQLSTATE';
            END IF; RETURN NEW; END \$f\$");
        DB::statement("CREATE TRIGGER {$name} AFTER INSERT ON public.invoices FOR EACH ROW WHEN (NEW.billing_schema_version=1) EXECUTE FUNCTION public.{$name}()");
        $this->faultTriggers[] = ['invoices', $name];
        $this->sequences[] = $sequence;

        return $sequence;
    }
}
