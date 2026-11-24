<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$secondaryChange = static function ($observer, string $kind, array $g, array $f): void {
    $observer->transaction(static function () use ($observer, $kind, $g, $f): void {
        $observer->statement("SET LOCAL lock_timeout='4s'");
        $observer->statement("SET LOCAL statement_timeout='7s'");
        if (in_array($kind, ['revoke', 'reactivate'], true)) {
            $state = $observer->table('proof_scan_policy_heads')->sole();
            $observer->scalar('SELECT public.proof_scan_set_head(?,?,?,?,public.proof_scan_authority())',
                [$state->policy_id, $state->admission_incarnation, $state->epoch, $kind === 'revoke' ? 'held' : 'active']);

            return;
        }
        if ($kind === 'rescan') {
            $version = $observer->table('proof_scan_subjects')->where('id', $f['subject'])->value('state_version');
            $observer->scalar("SELECT public.proof_scan_rescan_or_dispose(?::uuid, ?::bigint, 'rescan', public.proof_scan_authority())",
                [$f['subject'], $version]);

            return;
        }
        $p = $g['p'];
        $observer->table('users')->whereIn('id', [$p['admin'], $p['family'], $p['next_family']])->orderBy('id')->lockForUpdate()->get();
        $observer->table('elderlies')->where('id', $p['elderly'])->lockForUpdate()->first();
        $observer->table('service_enrollments')->where('id', $p['enrollment'])->lockForUpdate()->first();
        $observer->table('financial_ownership_periods')->where('elderly_id', $p['elderly'])->orderBy('id')->lockForUpdate()->get();
        $observer->table('invoices')->where('id', $f['invoice'])->lockForUpdate()->first();
        $observer->table('payments')->where('invoice_id', $f['invoice'])->orderBy('id')->lockForUpdate()->get();
        $at = '2026-11-05 00:00:00.123456+07';
        $period = $g['period'];
        $observer->table('financial_ownership_periods')->where('id', $period['id'])->update(['effective_to' => $at]);
        $replacement = [...$period, 'id' => (string) Str::uuid(), 'family_id' => $p['next_family'],
            'previous_period_id' => $period['id'], 'effective_from' => $at, 'effective_to' => null,
            'recorded_at' => $at, 'source' => 'family_transfer'];
        $observer->table('financial_ownership_periods')->insert($replacement);
        $observer->table('elderlies')->where('id', $p['elderly'])->update(['family_id' => $p['next_family']]);
        foreach (['ownership_closed' => $period['id'], 'ownership_opened' => $replacement['id']] as $action => $target) {
            $observer->table('billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => null,
                'actor_id' => $p['admin'], 'invoice_id' => null, 'ownership_period_id' => $target, 'revision_id' => null,
                'payment_id' => null, 'invoice_sequence' => null, 'action' => $action, 'occurred_at' => $at,
                'reason_code' => null, 'private_note' => null, 'details' => '{"v":1}']);
        }
    }, 1);
};
$test('T05', static function () use ($newProof, $login, $request, $observe, $reader, $grants, $assert, $secondaryChange, $scan, $guard): array {
    $traces = [];
    foreach (['transfer', 'revoke', 'rescan'] as $change) {
        foreach (['change_first', 'grant_first'] as $order) {
            [$g, $f] = $newProof();
            $family = $login($g['p']['family']);
            $primaryPid = DB::scalar('SELECT pg_backend_pid()');
            $barrier = [];
            $eventClass = $order === 'change_first' ? TransactionBeginning::class : TransactionCommitted::class;
            $started = microtime(true);
            try {
                $result = $observe(static function ($events) use ($eventClass, $primaryPid, $started, $change, $secondaryChange, $reader, $g, $f, &$barrier): void {
                    $events->listen($eventClass, static function () use ($primaryPid, $started, $change, $secondaryChange, $reader, $g, $f, &$barrier): void {
                        $barrier = $reader(static function ($observer, int $primary, int $secondary) use ($started, $change, $secondaryChange, $g, $f): array {
                            $prior = $observer->table('proof_scan_audit_events')->where('subject_id', $f['subject'])
                                ->where('event_type', 'protected_get_granted')->count();
                            $secondaryChange($observer, $change, $g, $f);

                            return ['primary_pid' => $primary, 'secondary_pid' => $secondary,
                                'audit_seen_before_secondary_commit' => $prior, 'secondary_commit_ms_since_request' => round((microtime(true) - $started) * 1000, 3)];
                        }, $primaryPid);
                    });
                }, static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); });
                $expected = $order === 'grant_first' ? 1 : 0;
                $deniedStatus = $change === 'transfer' ? 404 : 503;
                $assert(($barrier['audit_seen_before_secondary_commit'] ?? null) === $expected && $grants($f) === $expected
                    && $result->getStatusCode() === ($expected ? 200 : $deniedStatus)
                    && ($expected ? $result->getContent() === $f['bytes'] : ! $result->headers->has('Content-Disposition')),
                    'two_backend_commit_order_not_proven');
                $again = $request($family, 'GET', $f['path']);
                $assert($again->getStatusCode() === $deniedStatus && $grants($f) === $expected
                    && ! $again->headers->has('Content-Disposition'), 'subsequent_grant_reused_old_authority');
                $traces[$change][$order] = [...$barrier, 'http' => $result->getStatusCode(), 'proof_bytes' => $expected ? strlen($f['bytes']) : 0,
                    'next_http' => $again->getStatusCode(), 'committed_grants' => $grants($f)];
            } finally {
                if ($change === 'revoke' && DB::table('proof_scan_policy_heads')->value('admission') === 'held') {
                    $reader(static fn ($observer) => $secondaryChange($observer, 'reactivate', $g, $f), $primaryPid);
                }
                if ($change === 'rescan') {
                    $scan($f, 'clean');
                }
            }
        }
    }

    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $identity = $guard->identity;
    $db = DB::connection();
    $guard->assertTarget($db);
    $primaryPid = $db->scalar('SELECT pg_backend_pid()');
    $version = $db->table('proof_scan_subjects')->where('id', $f['subject'])->value('state_version');
    $settings = ['host' => $identity['host'], 'port' => $identity['connection_port'], 'dbname' => $identity['database'],
        'user' => $identity['owner'], 'password' => (string) $db->getConfig('password'), 'connect_timeout' => '3',
        'sslmode' => 'prefer', 'options' => '-c timezone=UTC'];
    $dsn = implode(' ', array_map(static fn (string $name, string $value) => $name."='".addcslashes($value, "\\'")."'",
        array_keys($settings), array_values($settings)));
    $peer = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
    unset($dsn, $settings);
    $assert($peer instanceof PgSql\Connection, 'retest_overlap_secondary_connect_failed');
    $overlap = [];
    try {
        $idResult = pg_query($peer, "SELECT current_database() AS database, current_user AS owner, session_user AS session_role,
            oid::text, pg_get_userbyid(datdba) AS db_owner, inet_server_addr()::text AS address, inet_server_port()::text AS port,
            shobj_description(oid,'pg_database') AS marker, pg_backend_pid() AS pid FROM pg_database WHERE datname=current_database()");
        $row = pg_fetch_assoc($idResult);
        $pid = (int) $row['pid'];
        $assert($row['database'] === $identity['database'] && $row['oid'] === $identity['oid'] && $pid !== $primaryPid
            && $row['owner'] === $identity['owner'] && $row['session_role'] === $identity['owner'] && $row['db_owner'] === $identity['owner']
            && $row['address'] === $identity['address'] && $row['port'] === $identity['port']
            && hash_equals('silansia-phase1:'.$identity['token'], $row['marker']), 'retest_overlap_identity_mismatch');
        unset($row, $idResult);
        $assert((bool) pg_query($peer, "BEGIN ISOLATION LEVEL READ COMMITTED READ WRITE; SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='7s'"),
            'retest_overlap_begin_failed');
        $result = $observe(static function ($events) use ($peer, $primaryPid, $pid, $f, $version, $assert, &$overlap): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($peer, $primaryPid, $pid, $f, $version, $assert, &$overlap): void {
                if (! str_contains($event->sql, 'SELECT public.proof_scan_append_audit(') || $overlap !== []) {
                    return;
                }
                $sent = pg_send_query_params($peer, 'SELECT public.proof_scan_rescan_or_dispose($1::uuid, $2::bigint, \'rescan\', public.proof_scan_authority())',
                    [$f['subject'], $version]);
                $assert($sent, 'retest_overlap_dispatch_failed');
                $start = hrtime(true);
                do {
                    $blocked = DB::scalar('SELECT ?::int=ANY(pg_blocking_pids(?::int))', [$primaryPid, $pid]);
                    if ($blocked === true) {
                        $overlap = ['blocking_backend_pid' => $primaryPid, 'waiting_backend_pid' => $pid,
                            'row_lock_observed' => true, 'barrier_ms' => round((hrtime(true) - $start) / 1000000, 3)];
                        return;
                    }
                    usleep(10000);
                } while ((hrtime(true) - $start) < 1000000000);
                throw new RuntimeException('retest_overlap_not_seen');
            });
        }, static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); });
        $peerResult = pg_get_result($peer);
        $assert($peerResult instanceof PgSql\Result && pg_result_status($peerResult) === PGSQL_TUPLES_OK
            && pg_get_result($peer) === false && (bool) pg_query($peer, 'COMMIT'), 'retest_rescan_after_grant_failed');
        $assert(($overlap['row_lock_observed'] ?? false) && $result->getStatusCode() === 200
            && $result->getContent() === $f['bytes'] && $grants($f) === 1, 'retest_grant_overlap_missing');
    } finally {
        pg_close($peer);
    }
    $after = $request($family, 'GET', $f['path']);
    $assert($after->getStatusCode() === 503 && $grants($f) === 1, 'retest_rescan_stale_grant_reused');
    $scan($f, 'clean');

    return ['ordered_two_backend_barriers' => $traces, 'measured_rescan_overlap' => [...$overlap, 'first_http' => 200,
        'first_bytes' => strlen($f['bytes']), 'subsequent_http' => 503]];
});

$test('S03', static function () use ($newProof, $login, $assertCleanGet, $deny, $assert, &$record): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $remaining = (float) DB::scalar("SELECT greatest(0, extract(epoch FROM received_at+interval '120 seconds'-clock_timestamp()))
        FROM proof_scan_verdicts WHERE subject_id=?", [$f['subject']]);
    $until = microtime(true) + $remaining + 0.15;
    while (microtime(true) < $until) {
        usleep((int) (max(0, min(0.25, $until - microtime(true))) * 1000000));
    }
    $denial = $deny($family, $f, 503);
    $state = DB::table('proof_scan_subjects')->where('id', $f['subject'])->sole();
    $assert($state->state === 'CLEAN_ELIGIBLE', 'freshness_fixture_must_exercise_current_projection');

    return ['status' => 'PARTIAL', 'actual_expiry' => $denial, 'nominal_freshness_seconds' => 120,
        'state_label_unchanged' => true, 'generation' => (int) $state->generation, 'version' => (int) $state->state_version,
        'stale_generation_ordered' => isset($record['access']['cases']['T05']['ordered_two_backend_barriers']['rescan']),
        'fence_and_identity_http_executed' => false];
});
