<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use PgSql\Result;
use RuntimeException;

final class AcademicProofScanClaimRace
{
    public function __construct(
        private readonly PostgresTestGuard $guard,
        private readonly Closure $verifyOwned,
        private readonly Closure $record,
    ) {}

    public function run(array $jobs): array
    {
        ($this->verifyOwned)();
        $this->check(extension_loaded('pgsql') && count($jobs) === 2 && $jobs[0] !== $jobs[1], 'race_precondition');
        $identity = $this->guard->identity;
        $this->check($identity['host'] === '127.0.0.1' && $identity['connection_port'] === '5432', 'race_loopback_required');
        $parent = DB::connection();
        $parentPid = (int) $parent->scalar('SELECT pg_backend_pid()');
        $settings = [
            'host' => '127.0.0.1', 'hostaddr' => '127.0.0.1', 'port' => '5432', 'dbname' => $identity['database'],
            'user' => $identity['owner'], 'password' => (string) $parent->getConfig('password'),
            'connect_timeout' => '3', 'sslmode' => 'prefer', 'options' => '-c timezone=UTC',
        ];
        $dsn = implode(' ', array_map(static fn (string $key, string $value) => $key."='".addcslashes($value, "\\'")."'",
            array_keys($settings), array_values($settings)));
        unset($settings);
        $peers = [];
        $pids = [];
        try {
            foreach ($jobs as $i => $job) {
                $peer = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
                $this->check($peer instanceof Connection, 'race_connect_failed');
                $peers[$i] = $peer;
                $pids[$i] = $this->assertPeer($peer);
                $this->check($pids[$i] !== $parentPid, 'race_reused_parent');
            }
            unset($dsn);
            $this->check($pids[0] !== $pids[1], 'race_backend_must_differ');
            $this->assertOnlyPeers($pids);
            foreach ($peers as $i => $peer) {
                $this->check($this->assertPeer($peer) === $pids[$i], 'race_peer_changed');
                $this->query($peer, "BEGIN ISOLATION LEVEL READ COMMITTED READ WRITE;
                    SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='9s';
                    SET LOCAL idle_in_transaction_session_timeout='12s'");
            }
            $this->assertOnlyPeers($pids);
            ($this->record)('race_barrier', ['ready_backend_pids' => $pids, 'distinct_jobs' => true, 'exact_identity_verified' => true]);
            foreach ($peers as $i => $peer) {
                $this->check(@pg_send_query_params($peer,
                    'SELECT public.proof_scan_claim_attempt($1::uuid, 1, public.proof_scan_authority(), public.proof_scan_authority())',
                    [$jobs[$i]]), 'race_send_failed');
            }

            $winner = null;
            $blocked = false;
            $deadline = hrtime(true) + 3_500_000_000;
            do {
                foreach ($peers as $i => $peer) {
                    if ($winner === $i || pg_connection_busy($peer)) {
                        continue;
                    }
                    $result = pg_get_result($peer);
                    $this->check($result instanceof Result && pg_result_status($result) === PGSQL_TUPLES_OK && $winner === null,
                        'race_expected_only_one_claim_before_commit');
                    $this->check(pg_get_result($peer) === false, 'race_winner_extra_result');
                    $winner = $i;
                }
                if ($winner !== null) {
                    $loser = 1 - $winner;
                    $blocked = $parent->scalar('SELECT ?::int = ANY(pg_blocking_pids(?::int))', [$pids[$winner], $pids[$loser]]) === true;
                    if ($blocked) {
                        break;
                    }
                }
                usleep(20_000);
            } while (hrtime(true) < $deadline);
            $this->check($winner !== null && $blocked, 'race_overlap_not_observed');
            $this->assertOnlyPeers($pids);
            ($this->record)('race_overlap', ['blocker_pid' => $pids[$winner], 'waiting_pid' => $pids[$loser],
                'observed_before_winner_commit' => true]);
            $this->query($peers[$winner], 'COMMIT');

            $deadline = hrtime(true) + 4_000_000_000;
            while (pg_connection_busy($peers[$loser]) && hrtime(true) < $deadline) {
                usleep(20_000);
            }
            $this->check(! pg_connection_busy($peers[$loser]), 'race_loser_timeout');
            $rejection = pg_get_result($peers[$loser]);
            $this->check($rejection instanceof Result && pg_result_status($rejection) === PGSQL_FATAL_ERROR, 'race_rejection_required');
            $sqlstate = pg_result_error_field($rejection, PGSQL_DIAG_SQLSTATE);
            $rule = pg_result_error_field($rejection, PGSQL_DIAG_CONSTRAINT_NAME);
            $this->check(pg_get_result($peers[$loser]) === false, 'race_loser_extra_result');
            $this->assertOnlyPeers($pids);
            $this->query($peers[$loser], 'ROLLBACK');

            return [
                ['status' => 'committed', 'backend_pid' => $pids[$winner]],
                ['status' => 'rejected', 'backend_pid' => $pids[$loser], 'sqlstate' => $sqlstate, 'rule' => $rule],
            ];
        } finally {
            // Close only the native client objects opened above. No backend termination or forced DROP.
            foreach ($peers as $peer) {
                pg_close($peer);
            }
        }
    }

    private function assertPeer(Connection $peer): int
    {
        $i = $this->guard->identity;
        $row = pg_fetch_assoc($this->query($peer, "SELECT d.oid::text AS oid, current_database() AS name, current_user AS role,
            session_user AS session_role, pg_get_userbyid(d.datdba) AS owner, shobj_description(d.oid, 'pg_database') AS marker,
            inet_server_addr()::text AS address, inet_server_port()::text AS port, current_setting('TimeZone') AS timezone,
            current_setting('server_version_num') AS version, pg_backend_pid() AS pid
            FROM pg_database d WHERE d.datname=current_database()"));
        $this->check($row && $row['oid'] === $i['oid'] && $row['name'] === $i['database'] && $row['role'] === $i['owner']
            && $row['session_role'] === $i['owner'] && $row['owner'] === $i['owner']
            && hash_equals('silansia-phase1:'.$i['token'], $row['marker'] ?? '')
            && $row['address'] === $i['address'] && $row['port'] === $i['port'] && $row['timezone'] === 'UTC'
            && (int) $row['version'] >= 180000 && (int) $row['version'] < 190000, 'race_owned_identity_mismatch');
        return (int) $row['pid'];
    }

    private function assertOnlyPeers(array $pids): void
    {
        FrameworkRuntimeIsolation::assertProtected();
        $this->guard->assertSafe(app(), DB::connection());
        $actual = array_map(static fn (object $peer) => (int) $peer->pid, DB::select(
            'SELECT pid FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()'));
        sort($actual);
        sort($pids);
        $this->check($actual === $pids, 'race_unexpected_peer');
    }

    private function query(Connection $peer, string $sql): Result
    {
        $result = @pg_query($peer, $sql);
        $this->check($result instanceof Result, 'race_query_failed');
        return $result;
    }

    private function check(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new RuntimeException($reason);
        }
    }
}
