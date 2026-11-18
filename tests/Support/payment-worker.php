<?php

use App\Models\Elderly;
use App\Models\User;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentService;
use App\Services\ResidentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrameworkRuntimeIsolation;
use Tests\Support\PaymentProofFixtureStore;
use Tests\Support\PaymentSqlFixture;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$emit = null;
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    FrameworkRuntimeIsolation::attach($app);
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    if (config('payment_core.enabled') !== true) {
        throw new LogicException('payment_gate_closed');
    }
    $data = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Ap92d_[a-f0-9]{16}\z/', $data['case'] ?? '')) {
        throw new LogicException('case_invalid');
    }
    $app->instance(PaymentProofStore::class, new PaymentProofFixtureStore);
    $inside = false;
    $counts = [];
    $transactions = 0;
    $emit = function (string $point, array $extra = []) use ($data, &$transactions) {
        echo json_encode(['case' => $data['case'], 'point' => $point, 'os_pid' => getmypid(), 'backend_pid' => DB::scalar('SELECT pg_backend_pid()'),
            'transaction' => $transactions, ...$extra], JSON_THROW_ON_ERROR).PHP_EOL;
        fflush(STDOUT);
    };
    $point = function (string $name) use (&$inside, &$counts, $data, $emit) {
        $inside = true;
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $emit($name, ['occurrence' => $counts[$name], 'tx_level' => DB::transactionLevel()]);
        if (in_array($name.':'.$counts[$name], $data['pause'] ?? [], true)) {
            if (trim(fgets(STDIN) ?: '') !== 'go') {
                throw new LogicException('barrier_invalid');
            }
            $emit('released', ['barrier' => $name.':'.$counts[$name]]);
        }
        $inside = false;
    };
    $app['events']->listen(TransactionBeginning::class, function () use (&$transactions, $point) {
        $transactions++;
        $point('begin');
    });
    $app['events']->listen(TransactionCommitting::class, fn () => $point('before_commit'));
    DB::listen(function ($query) use (&$inside, $point) {
        if ($inside) {
            return;
        }
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            foreach (['users', 'elderlies', 'service_enrollments', 'financial_ownership_periods', 'invoices', 'payments'] as $table) {
                if (str_contains($sql, '"'.$table.'"')) {
                    $point('lock_'.$table);
                    break;
                }
            }
        }
    });
    $emit('ready', ['database' => $guard->identity['database'], 'oid' => $guard->identity['oid']]);
    if (trim(fgets(STDIN) ?: '') !== 'go') {
        throw new LogicException('release_required');
    }
    $emit('released', ['barrier' => 'ready']);
    $operation = $data['operation'];
    if (in_array($operation, ['submit', 'verify', 'reject', 'reconcile'], true)) {
        $command = new PaymentCommand($data['command']);
        $actor = User::find($command->data['actor_id']);
        if ($actor) {
            Auth::setUser($actor);
        }
        $service = app(PaymentService::class);
        $result = $service->{$operation}($command);
        $emit('outcome', ['kind' => 'success', 'operation' => $result->operation, 'replayed' => $result->replayed,
            'invoice' => $result->invoiceId, 'payment' => $result->paymentId, 'fact' => $result->fact()]);
    } elseif ($operation === 'fixture_deactivate') {
        DB::beginTransaction();
        DB::table('users')->where('id', $data['actor'])->lockForUpdate()->first();
        DB::table('users')->where('id', $data['actor'])->update(['is_active' => false]);
        DB::commit();
        $emit('outcome', ['kind' => 'success', 'operation' => $operation]);
    } elseif ($operation === 'transfer') {
        app(ResidentService::class)->reassignFamily(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']),
            ['family_id' => $data['new_family'], 'current_family_id' => $data['old_family'], 'confirm_reassignment' => true]);
        $emit('outcome', ['kind' => 'success', 'operation' => 'transfer']);
    } elseif (in_array($operation, ['fixture_revise', 'fixture_cancel'], true)) {
        PaymentSqlFixture::mutate($data['invoice'], $data['actor'], $operation === 'fixture_cancel');
        $emit('outcome', ['kind' => 'success', 'operation' => $operation]);
    } else {
        throw new LogicException('operation_invalid');
    }
} catch (PaymentFailure $e) {
    $emit('outcome', ['kind' => $e->kind, 'sqlstate' => $e->sqlState, 'rule' => $e->rule]);
} catch (PDOException $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    preg_match('/Integritas billing ditolak \(([a-z_]+)\)|constraint "([a-z_]+)"/', $e->errorInfo[2] ?? '', $m);
    $state = $e->errorInfo[0] ?? (string) $e->getCode();
    if (! $emit || ! str_starts_with($state, '23')) {
        echo '{"point":"outcome","kind":"infrastructure"}';
        exit(1);
    }
    $emit('outcome', ['kind' => 'integrity', 'sqlstate' => $state, 'rule' => ($m[1] ?? '') ?: ($m[2] ?? null)]);
} catch (Throwable) {
    echo '{"point":"outcome","kind":"infrastructure"}';
    exit(1);
}
