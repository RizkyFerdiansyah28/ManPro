<?php

use App\Services\Billing\BillingTime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\P93dWebApplication;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = P93dWebApplication::boot();
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertSafe($app, DB::connection());
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (($input['action'] ?? '') === 'token_fixture') {
        $data = json_decode(Crypt::decryptString($input['token']), true, flags: JSON_THROW_ON_ERROR);
        if (($input['kind'] ?? '') === 'audience') {
            $data['audience'] = 'p93d-wrong-audience';
        } elseif (($input['kind'] ?? '') === 'expired') {
            $at = DB::scalar("SELECT to_char((clock_timestamp()-INTERVAL '6 minutes') AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
            $data['issued_at'] = $at;
            $data['expires_at'] = BillingTime::format(BillingTime::instant($at)->modify('+5 minutes'));
        } else {
            throw new LogicException('p93d_token_fixture_rejected');
        }
        echo json_encode(['ok' => true, 'token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($input['action'] ?? '') === 'expire_session') {
        if (! DB::table('users')->where('id', $input['actor'])->where('email', 'like', 'p93d-%@example.test')->exists()) {
            throw new LogicException('p93d_fixture_actor_required');
        }
        DB::table('sessions')->where('user_id', $input['actor'])->delete();
        echo '{"ok":true}';
        exit;
    }
    if (($input['action'] ?? '') !== 'snapshot' || ! is_array($input['residents'] ?? null)) {
        throw new LogicException('p93d_control_rejected');
    }
    DB::beginTransaction();
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    DB::statement("SET LOCAL statement_timeout='10s'");
    $result = [];
    foreach (['elderlies' => 'id', 'service_enrollments' => 'elderly_id', 'financial_ownership_periods' => 'elderly_id',
        'invoices' => 'elderly_id', 'invoice_revisions' => 'elderly_id'] as $table => $column) {
        $rows = DB::table($table)->whereIn($column, $input['residents'])->orderBy('id')->get()->toJson();
        $result[$table] = hash('sha256', $rows);
    }
    $ids = DB::table('invoices')->whereIn('elderly_id', $input['residents'])->pluck('id');
    foreach (['payments', 'billing_requests', 'billing_actions'] as $table) {
        $result[$table] = hash('sha256', DB::table($table)->whereIn('invoice_id', $ids)->orderBy('id')->get()->toJson());
    }
    $result['roots'] = DB::table('invoices')->whereIn('elderly_id', $input['residents'])->count();
    $result['payments_count'] = DB::table('payments')->whereIn('invoice_id', $ids)->count();
    $result['revision_count'] = DB::table('invoice_revisions')->whereIn('invoice_id', $ids)->count();
    $result['request_count'] = DB::table('billing_requests')->whereIn('invoice_id', $ids)->count();
    $result['action_counts'] = DB::table('billing_actions')->whereIn('invoice_id', $ids)->selectRaw('action,count(*)::int AS count')->groupBy('action')->orderBy('action')->get()->all();
    $result['invoice_facts'] = DB::table('invoices')->whereIn('elderly_id', $input['residents'])
        ->orderBy('id')->get(['id', 'elderly_id', 'amount', 'status', 'bill_to_family_id'])->all();
    $result['protected_domain'] = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
        if (! in_array($row->tablename, ['users', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'payments', 'financial_ownership_periods'], true)) {
            $result['protected_domain'][$row->tablename] = DB::scalar('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\'::jsonb)::text) FROM public."'.$row->tablename.'" t');
        }
    }
    DB::commit();
    echo json_encode(['ok' => true, 'snapshot' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    if (isset($app)) {
        try {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } catch (Throwable) {
            // Fail closed without serializing connection or token details.
        }
    }
    echo '{"ok":false,"reason":"p93d_control_rejected"}';
    exit(1);
}
