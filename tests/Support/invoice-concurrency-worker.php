<?php

// Instrumentasi/barrier hanya worker test. Tidak menambah hook pada source aplikasi.
use App\Models\Elderly;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use App\Services\FinancialOwnershipLedgerService;
use App\Services\ResidentOperationsService;
use App\Services\ResidentService;
use App\Services\ServiceClassService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\InvoiceHttpWorkerRequest;
use Tests\Support\PostgresTestGuard;
use Tests\Support\TemporalLedgerClock;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$emit = null;
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    if (config('invoice_issuance.enabled') !== true || config('financial_ownership.enabled') !== true
        || config('financial_ownership.cutover_reference') !== 'owned-test-only-approval') {
        throw new LogicException('test_gate_context_invalid');
    }
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/\Ap92d_[a-f0-9]{16}\z/', $data['case'] ?? '')
        || ! in_array($data['operation'] ?? null, ['issue', 'transfer', 'close', 'enroll', 'master', 'resident_inactive', 'actor_role', 'actor_inactive', 'http'], true)) {
        throw new LogicException('payload_invalid');
    }
    $case = $data['case'];
    DB::statement("SELECT set_config('application_name',?,false)", [$case]);
    $pid = DB::scalar('SELECT pg_backend_pid()');
    $inside = false;
    $counts = [];
    $transactions = 0;
    $faultUsed = false;
    $emit = function (string $point, array $extra = []) use ($case, &$pid, &$transactions): void {
        echo json_encode(['case' => $case, 'point' => $point, 'os_pid' => getmypid(), 'backend_pid' => $pid,
            'transaction' => $transactions, ...$extra], JSON_THROW_ON_ERROR).PHP_EOL;
        fflush(STDOUT);
    };
    $point = function (string $name, array $extra = []) use (&$inside, &$counts, $data, $emit): void {
        $previous = $inside;
        $inside = true;
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $emit($name, ['occurrence' => $counts[$name], 'tx_level' => DB::transactionLevel(), ...$extra]);
        if (in_array($name.':'.$counts[$name], $data['pause'] ?? [], true)) {
            if (trim(fgets(STDIN) ?: '') !== 'go') {
                throw new LogicException('barrier_not_released');
            }
            $emit('released', ['barrier' => $name.':'.$counts[$name]]);
        }
        $inside = $previous;
    };
    DB::listen(function ($query) use (&$inside, &$counts, $point, $data): void {
        if ($inside) {
            return;
        }
        $sql = strtolower($query->sql);
        if (DB::transactionLevel() === 0 && str_contains($sql, '"a"."actor_id"') && str_contains($sql, '"ownership_period_id"')) {
            $point('preread');
        }
        if (str_contains($sql, 'for update')) {
            $table = null;
            foreach (['users', 'elderlies', 'service_enrollments', 'financial_ownership_periods', 'invoices', 'service_classes'] as $name) {
                if (str_contains($sql, '"'.$name.'"')) {
                    $table = $name;
                    break;
                }
            }
            if ($table) {
                $point('lock_'.$table, ['ids' => $query->bindings, 'ordered_uuid' => str_contains($sql, 'order by "id" asc')]);
                if ($table === 'users' && ($data['fault'] ?? '') === 'statement') {
                    $inside = true;
                    DB::statement("SET LOCAL statement_timeout='100ms'");
                    $inside = false;
                }
            }
        }
        if (str_starts_with($sql, 'insert into "public"."invoices"')) {
            $point('root_inserted');
        }
        if (str_contains($sql, '/* invoice-http-snapshot */')) {
            $point('http_snapshot', ['read_only' => DB::scalar('SHOW transaction_read_only')]);
        }
    });
    $app['events']->listen(TransactionBeginning::class, function () use (&$inside, &$transactions, &$pid, $point): void {
        if (DB::transactionLevel() !== 1) {
            return;
        }
        $inside = true;
        $transactions++;
        $pid = DB::scalar('SELECT pg_backend_pid()');
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::statement("SET LOCAL statement_timeout='15s'");
        $at = DB::scalar('SELECT transaction_timestamp()::text');
        $txid = DB::scalar('SELECT txid_current()::text');
        $inside = false;
        $point('begin', ['transaction_started_at' => $at, 'txid' => $txid]);
    });
    $app['events']->listen(TransactionRolledBack::class, fn () => $point('rollback'));
    $app['events']->listen(TransactionCommitting::class, function () use ($point, $data, &$inside): void {
        if (DB::transactionLevel() !== 1) {
            return;
        }
        $point('before_commit');
        if (($data['fault'] ?? '') === 'projection') {
            $inside = true;
            // Corruption fixture pada graph BELUM COMMITTED, bukan workflow koreksi aplikasi.
            DB::table('invoices')->where('elderly_id', $data['command']['elderlyId'])
                ->where('billing_month', $data['command']['billingMonth'])->update(['amount' => DB::raw('amount+1')]);
            $inside = false;
        }
    });
    $app['events']->listen(TransactionCommitted::class, function () use ($point, $data, &$faultUsed, &$counts): void {
        if (DB::transactionLevel() !== 0) {
            return;
        }
        $point('committed');
        if (in_array($data['fault'] ?? '', ['ack_loss', 'ack_always'], true) && (! $faultUsed || $data['fault'] === 'ack_always')
            && (($data['operation'] ?? '') !== 'http' || ($counts['root_inserted'] ?? 0) > 0)) {
            $faultUsed = true;
            $error = new PDOException('test-only acknowledgement loss');
            $error->errorInfo = ['08007', 0, 'test-only'];
            throw $error;
        }
    });
    if (isset($data['ledger_clock'])) {
        if ($data['operation'] !== 'transfer') {
            throw new LogicException('ledger_clock_only_for_test_transfer');
        }
        $app->instance(FinancialOwnershipLedgerService::class, new TemporalLedgerClock($data['ledger_clock']));
    }
    if (isset($data['php_clock'])) {
        if (! in_array($data['operation'], ['enroll', 'close'], true)) {
            throw new LogicException('php_clock_only_for_test_enrollment');
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse($data['php_clock']));
    }
    $httpSession = $data['operation'] === 'http' ? InvoiceHttpWorkerRequest::prepare($data) : [];
    $emit('ready', ['database' => $guard->identity['database'], 'oid' => $guard->identity['oid'],
        'ledger_clock_synthetic' => $data['ledger_clock'] ?? null, 'php_clock_synthetic' => $data['php_clock'] ?? null,
        'postgres_clock_actual' => DB::scalar('SELECT clock_timestamp()::text')]);
    $httpBody = $data['operation'] === 'http' ? json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR) : [];
    if (trim(fgets(STDIN) ?: '') !== 'go') {
        throw new LogicException('start_barrier_missing');
    }
    switch ($data['operation']) {
        case 'http':
            $outcome = InvoiceHttpWorkerRequest::handle($data, $httpBody, $httpSession);
            break;
        case 'issue':
            $c = $data['command'];
            $point('observed_root', ['absent' => ! DB::table('invoices')->where('elderly_id', $c['elderlyId'])->where('billing_month', $c['billingMonth'])->exists()]);
            $command = new InvoiceIssueCommand($c['actorId'], $c['elderlyId'], $c['billingMonth'], $c['requestId'],
                new InvoiceIssueConfirmation($c['confirmation']['fingerprint']));
            $result = app(InvoiceIssuanceService::class)->issue($command);
            $outcome = ['decision' => $result->decision->value, 'reason' => $result->reason->value,
                'invoice' => $result->invoiceId, 'revision' => $result->revisionId, 'amount' => $result->amount, 'replayed' => $result->replayed];
            break;
        case 'transfer':
            app(ResidentService::class)->reassignFamily(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']),
                ['family_id' => $data['family'], 'current_family_id' => $data['expected'], 'confirm_reassignment' => true]);
            $outcome = ['operation' => 'transfer'];
            break;
        case 'close':
            app(ResidentOperationsService::class)->endEnrollment(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']), ServiceEnrollment::findOrFail($data['enrollment']));
            $outcome = ['operation' => 'close'];
            break;
        case 'enroll':
            $record = app(ResidentOperationsService::class)->enroll(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']), $data['class']);
            $outcome = ['operation' => 'enroll', 'enrollment' => $record->id];
            break;
        case 'master':
            $class = ServiceClass::findOrFail($data['class']);
            app(ServiceClassService::class)->update(User::findOrFail($data['actor']), $class, ['name' => $class->name, 'monthly_price' => '9000.00']);
            $outcome = ['operation' => 'master'];
            break;
        case 'resident_inactive':
            app(ResidentService::class)->setActive(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']), false);
            $outcome = ['operation' => 'resident_inactive'];
            break;
        case 'actor_role':
        case 'actor_inactive':
            // Administrasi luar aplikasi disimulasikan HANYA test. Tidak ada endpoint Admin-role/deactivation baru.
            DB::transaction(function () use ($data) {
                DB::table('users')->where('id', $data['target'])->lockForUpdate()->first();
                DB::table('users')->where('id', $data['target'])->update($data['operation'] === 'actor_role' ? ['role' => 'perawat'] : ['is_active' => false]);
            });
            $outcome = ['operation' => $data['operation']];
            break;
    }
    $emit('outcome', ['ok' => true, 'sqlstate' => null, ...$outcome]);
} catch (InvoiceIssuanceFailure $error) {
    $emit('outcome', ['ok' => false, 'kind' => $error->kind, 'sqlstate' => $error->sqlState, 'rule' => $error->rule]);
} catch (ValidationException $error) {
    $emit('outcome', ['ok' => false, 'kind' => 'validation', 'sqlstate' => null, 'fields' => array_keys($error->errors())]);
} catch (Throwable $error) {
    if ($emit) {
        $emit('outcome', ['ok' => false, 'kind' => 'infrastructure', 'class' => $error::class,
            'sqlstate' => $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null]);
    } else {
        echo json_encode(['point' => 'safety_rejected', 'kind' => $error::class], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    exit(1);
}
