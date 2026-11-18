<?php

namespace Tests\Support;

use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

abstract class PaymentCoreTestCase extends InvoiceConcurrencyTestCase
{
    protected PaymentProofFixtureStore $proofStore;

    protected string $evidenceEnvironment = 'SILANSIA_P94B_EVIDENCE_FILE';

    protected string $evidencePrefix = 'SILANSIA_P9_4B_TEST_EVIDENCE_';

    private array $paymentFaults = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment_core.enabled' => true]);
        $this->proofStore = new PaymentProofFixtureStore;
        $this->app->instance(PaymentProofStore::class, $this->proofStore);
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe($this->app, DB::connection());
        $proofPath = base_path('docs/report/SILANSIA_P9_4B_ISOLATION_'.substr($guard->identity['database'], -16).'.json');
        if (! file_exists($proofPath)) {
            $proof = ['environment' => app()->environment(), 'connection' => config('database.default'),
                'actual_database' => DB::scalar('SELECT current_database()'), 'owned_identity_verified' => true,
                'config_cached' => app()->configurationIsCached(), 'isolated_cache_verified' => true,
                'host' => DB::connection()->getConfig('host'), 'port' => (string) DB::connection()->getConfig('port'),
                'payment_gate_test_only' => config('payment_core.enabled'), 'proof_implementation_test_only' => get_class($this->proofStore)];
            $this->assertNotFalse(file_put_contents($proofPath, json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        }
    }

    protected function tearDown(): void
    {
        if (! isset($this->proofStore)) {
            parent::tearDown();

            return;
        }
        foreach ($this->workers as [$process, $input]) {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        foreach ($this->paymentFaults as [$table, $name]) {
            DB::statement("DROP TRIGGER {$name} ON public.{$table}");
            DB::statement("DROP FUNCTION public.{$name}()");
        }
        $this->proofStore->cleanup();
        parent::tearDown();
    }

    protected function paymentSource(array $changes = []): array
    {
        $s = $this->source($changes);
        $issueCommand = $this->command($s);
        $issue = app(InvoiceIssuanceService::class)->issue($issueCommand);

        return $s + ['issue' => $issue, 'issue_command' => $issueCommand, 'reference' => $this->proofStore->issue($s['family']->id, $issue->invoiceId)];
    }

    protected function submitCommand(array $s, array $changes = []): PaymentCommand
    {
        return new PaymentCommand(array_replace(['operation' => 'submit', 'actor_id' => $s['family']->id,
            'invoice_id' => $s['issue']->invoiceId, 'revision_id' => $s['issue']->revisionId,
            'request_id' => (string) Str::uuid(), 'proof_reference' => $s['reference'], 'confirm' => true], $changes));
    }

    protected function reviewCommand(array $s, string $payment, string $operation = 'verify', array $changes = []): PaymentCommand
    {
        $this->actingAs($s['admin']);
        $state = app(PaymentService::class)->reviewState($s['admin']->id, $s['issue']->invoiceId, $payment);

        return new PaymentCommand(array_replace(['operation' => $operation, 'actor_id' => $s['admin']->id,
            'invoice_id' => $s['issue']->invoiceId, 'revision_id' => $s['issue']->revisionId, 'request_id' => (string) Str::uuid(),
            'payment_id' => $payment, 'expected_state' => $state, 'confirm' => true,
            'bank_receipt_checked' => $operation === 'verify',
            ...($operation === 'reject' ? ['reason_code' => 'receipt_not_confirmed', 'public_reason' => 'Bukti belum memenuhi hasil review.', 'private_note' => 'Catatan privat fixture.'] : [])], $changes));
    }

    protected function pending(array $s): array
    {
        $this->actingAs($s['family']);
        $command = $this->submitCommand($s);

        return [$command, app(PaymentService::class)->submit($command)];
    }

    protected function paymentFailure(string $kind, callable $run, ?string $state = null, ?string $rule = null): PaymentFailure
    {
        try {
            $run();
            $this->fail('Harus ditolak: '.$kind);
        } catch (PaymentFailure $e) {
            $this->assertSame($kind, $e->kind);
            $this->assertSame($state, $e->sqlState);
            $this->assertSame($rule, $e->rule);

            return $e;
        }
    }

    protected function unchanged(array $before, array $allowed = []): void
    {
        $after = $this->fingerprints();
        foreach ($before as $table => $value) {
            if (! in_array($table, $allowed, true)) {
                $this->assertEquals($value, $after[$table], $table);
            }
        }
    }

    protected function paymentFault(string $stage, string $operation = 'submit'): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $table = match ($stage) {
            'payment' => 'payments', 'request' => 'billing_requests', default => 'billing_actions'
        };
        $when = $table === 'billing_actions' ? "WHEN (NEW.action LIKE 'payment_%')" : '';
        $name = 'test_payment_fault_'.bin2hex(random_bytes(5));
        DB::unprepared("CREATE FUNCTION public.{$name}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
            RAISE EXCEPTION USING ERRCODE='23514',CONSTRAINT='test_payment_fault',MESSAGE='test_payment_fault'; RETURN NEW; END \$f\$");
        $constraint = $stage === 'deferred' ? 'CONSTRAINT' : '';
        $deferred = $stage === 'deferred' ? 'DEFERRABLE INITIALLY DEFERRED' : '';
        $event = $stage === 'payment' && $operation !== 'submit' ? 'UPDATE' : 'INSERT';
        DB::statement("CREATE {$constraint} TRIGGER {$name} AFTER {$event} ON public.{$table} {$deferred} FOR EACH ROW {$when} EXECUTE FUNCTION public.{$name}()");
        $this->paymentFaults[] = [$table, $name];
    }

    protected function spawn(array $payload): int
    {
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $input = new InputStream;
        $process = new Process([PHP_BINARY, 'tests/Support/payment-worker.php'], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'PAYMENT_CORE_ENABLED' => 'true',
                'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true', 'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-test-only-approval',
                'INVOICE_ISSUANCE_ENABLED' => 'true'], $input);
        $input->write(json_encode(['case' => $this->caseId, ...$payload], JSON_THROW_ON_ERROR)."\n");
        $process->setTimeout(55);
        $process->start();
        $index = count($this->workers);
        $this->workers[] = [$process, $input, $process->getPid(), $payload];
        $ready = $this->awaitPoint($index, 'ready');
        $this->assertSame(PostgresTestGuard::fromEnvironment()->identity['database'], $ready['database']);
        $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $ready['backend_pid']);

        return $index;
    }
}
