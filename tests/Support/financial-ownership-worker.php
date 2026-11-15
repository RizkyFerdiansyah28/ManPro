<?php

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ResidentOperationsService;
use App\Services\ResidentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    if (config('financial_ownership.enabled') !== true || config('financial_ownership.cutover_reference') !== 'owned-test-only-approval') {
        throw new LogicException('test_gate_context_invalid');
    }
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (! in_array($data['operation'] ?? null, ['transfer', 'create', 'deactivate', 'append_successor', 'close_enrollment'], true)) {
        throw new LogicException('operation_invalid');
    }
    foreach (['admin_id', 'old_family_id', 'next_family_id', 'other_family_id'] as $key) {
        if (! Str::isUuid($data[$key] ?? '')) {
            throw new LogicException('payload_invalid');
        }
    }
    $callbackDone = false;
    $result = DB::transaction(function () use ($data, $guard, $app, &$callbackDone) {
        $guard->assertSafe($app, DB::connection());
        DB::statement("SET LOCAL lock_timeout = '20s'");
        DB::statement("SET LOCAL statement_timeout = '25s'");
        echo json_encode(['ready' => true, 'pid' => DB::scalar('SELECT pg_backend_pid()'),
            'transaction_started_at' => DB::scalar('SELECT transaction_timestamp()::text')], JSON_THROW_ON_ERROR).PHP_EOL;
        fflush(STDOUT);
        $admin = User::findOrFail($data['admin_id']);
        $id = $data['resident_id'] ?? null;
        switch ($data['operation']) {
            case 'create':
                $id = app(ResidentService::class)->create($admin, ['family_id' => $data['old_family_id'],
                    'name' => $data['name'], 'birth_date' => '1950-01-01'])->id;
                break;
            case 'transfer':
                app(ResidentService::class)->reassignFamily($admin, Elderly::findOrFail($id), ['family_id' => $data['next_family_id'],
                    'current_family_id' => $data['expected_family_id'], 'confirm_reassignment' => true]);
                break;
            case 'deactivate':
                app(AccountService::class)->setActive($admin, User::findOrFail($data['next_family_id']), false);
                break;
            case 'close_enrollment':
                app(ResidentOperationsService::class)->endEnrollment($admin, Elderly::findOrFail($id), ServiceEnrollment::findOrFail($data['enrollment_id']));
                break;
            case 'append_successor':
                // SQL negatif dengan ancestor protocol yang benar, bukan jalur bypass aplikasi.
                DB::table('users')->whereIn('id', [$data['admin_id'], $data['old_family_id'], $data['next_family_id'], $data['other_family_id']])
                    ->orderBy('id')->lockForUpdate()->get();
                DB::table('elderlies')->where('id', $id)->lockForUpdate()->first();
                DB::table('financial_ownership_periods')->where('elderly_id', $id)->orderBy('id')->lockForUpdate()->get();
                $at = DB::scalar('SELECT clock_timestamp()::text');
                DB::table('financial_ownership_periods')->insert(['id' => (string) Str::uuid(), 'elderly_id' => $id,
                    'family_id' => $data['other_family_id'], 'previous_period_id' => $data['root_period_id'],
                    'effective_from' => $at, 'effective_to' => null, 'recorded_by' => $data['admin_id'],
                    'recorded_at' => $at, 'source' => 'family_transfer']);
                break;
        }
        $callbackDone = true;

        return $id;
    });
    echo json_encode(['ok' => true, 'id' => $result, 'phase' => 'commit'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'validation', 'fields' => array_keys($exception->errors()), 'sqlstate' => null], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (PDOException $exception) {
    $state = $exception->errorInfo[0] ?? '';
    if (! in_array($state, ['23514', '23505'], true)) {
        echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'sqlstate' => $state], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
    preg_match('/(test_ledger_fault)|Integritas billing ditolak \((billing_[a-z_]+)\)|constraint "([a-z_]+)"/', $exception->errorInfo[2], $matches);
    echo json_encode(['ok' => false, 'kind' => 'integrity', 'sqlstate' => $state,
        'constraint' => ($matches[1] ?? '') ?: (($matches[2] ?? '') ?: ($matches[3] ?? '')),
        'phase' => ($callbackDone ?? false) ? 'commit' : 'write'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => $exception::class], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
