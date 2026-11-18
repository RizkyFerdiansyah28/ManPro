<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\P94dWebApplication;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = P94dWebApplication::boot();
    PostgresTestGuard::fromEnvironment()->assertSafe($app, DB::connection());
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (($input['action'] ?? '') === 'expired_context') {
        $data = json_decode(Crypt::decryptString($input['token']), true, flags: JSON_THROW_ON_ERROR);
        if (($data['purpose'] ?? '') !== 'command' || ! is_int($data['issued_at'])) {
            throw new LogicException('context_rejected');
        }
        $data['issued_at'] -= 600;
        $data['expires_at'] -= 600;
        echo json_encode(['ok' => true, 'token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($input['action'] ?? '') === 'expire_session') {
        if (! DB::table('users')->where('id', $input['actor'])->where('email', 'like', 'p94d-%@example.test')->exists()) {
            throw new LogicException('fixture_actor_required');
        }
        DB::table('sessions')->where('user_id', $input['actor'])->delete();
        echo '{"ok":true}';
        exit;
    }
    if (($input['action'] ?? '') !== 'snapshot' || ! is_array($input['residents'] ?? null)) {
        throw new LogicException('control_rejected');
    }
    DB::beginTransaction();
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    DB::statement("SET LOCAL statement_timeout='10s'");
    $ids = DB::table('invoices')->whereIn('elderly_id', $input['residents'])->pluck('id');
    $snapshot = ['invoices' => DB::table('invoices')->whereIn('id', $ids)->orderBy('id')->get(['id', 'amount', 'status', 'bill_to_family_id'])->all(),
        'payments' => DB::table('payments')->whereIn('invoice_id', $ids)->orderBy('submitted_at')->get(['id', 'invoice_id', 'status', 'amount', 'public_rejection_reason'])->all(),
        'requests' => DB::table('billing_requests')->whereIn('invoice_id', $ids)->count(),
        'actions' => DB::table('billing_actions')->whereIn('invoice_id', $ids)->count(),
        'revisions' => DB::table('invoice_revisions')->whereIn('invoice_id', $ids)->count(), 'protected_domain' => []];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
        if (! in_array($row->tablename, ['users', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'payments'], true)) {
            $snapshot['protected_domain'][$row->tablename] = DB::scalar('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\'::jsonb)::text) FROM public."'.$row->tablename.'" t');
        }
    }
    DB::commit();
    echo json_encode(['ok' => true, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo '{"ok":false,"reason":"owned_control_failed"}';
    exit(1);
}
