<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\AcademicProofScanWorker;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invoke each case on a fresh owned target with M10/M11 and an active synthetic head.
 * The authorized runner supplies its full guard, a PaymentService-backed synthetic submitter
 * (bytes -> job UUID), and a routine-backed head setter (admission -> committed epoch).
 */
final class AcademicProofScanWorkerCases
{
    public const CASES = [
        'missing_flag', 'missing_identity', 'invalid_identity', 'held_head',
        'clean', 'infected', 'unknown', 'unlisted_descriptor', 'infected_rescan_hold',
        'claim_commit_interruption', 'start_commit_interruption', 'result_before_commit',
        'result_after_commit', 'stale_envelopes', 'expired_envelope',
    ];

    private const UNLISTED_PNG = '89504e470d0a1a0a0000000d49484452000000010000000108000000003a7e9b550000000d494441547801010200fdff00e100e300e2fa92eae80000000049454e44ae426082';

    public function __construct(
        private readonly Closure $verifyOwned,
        private readonly Closure $submitSynthetic,
        private readonly Closure $setAdmission,
        private readonly Closure $invokeCommand,
        private readonly Closure $record,
    ) {}

    public function run(string $case): void
    {
        ($this->verifyOwned)();
        $this->check(in_array($case, self::CASES, true), 'unknown_worker_case');
        $this->check(! DB::table('public.proof_scan_jobs')->exists(), 'fresh_worker_fixture_required');
        $worker = app(AcademicProofScanWorker::class);
        if (in_array($case, ['missing_flag', 'missing_identity', 'invalid_identity'], true)) {
            $this->assertDeniedEnvironment($worker, $case);

            return;
        }
        $scenario = match ($case) {
            'infected', 'infected_rescan_hold' => 'infected',
            'unknown' => 'unknown',
            default => 'clean',
        };
        $bytes = $case === 'unlisted_descriptor'
            ? hex2bin(self::UNLISTED_PNG)
            : AcademicProofScanner::fixtureBytes($scenario);
        $job = ($this->submitSynthetic)($bytes);
        ($this->verifyOwned)();
        $before = $this->financialFingerprint();
        ($this->record)('initial', $this->state($job));

        try {
            match ($case) {
            'held_head' => $this->assertHeld($worker, $job),
            'unlisted_descriptor' => $this->assertInvalidDescriptor($worker, $job),
            'clean', 'infected' => $this->assertPositive($worker, $job, $scenario),
            'unknown' => $this->assertUnknownVerdict($worker, $job),
            'infected_rescan_hold' => $this->assertHoldAfterRescan($worker, $job),
            'claim_commit_interruption' => $this->assertInterrupted($worker, $job, 1, 'LEASED'),
            'start_commit_interruption' => $this->assertInterrupted($worker, $job, 2, 'RUNNING'),
            'result_before_commit', 'result_after_commit', 'stale_envelopes', 'expired_envelope' => $this->assertResultFault($worker, $job, $case),
            };
        } finally {
            ($this->verifyOwned)();
            ($this->record)('final', $this->state($job));
            $this->assertFinancial($before);
        }
    }

    /**
     * The runner must launch two verified owned connections, use a
     * barrier before each routine call, and release the winning COMMIT before joining
     * the losing call. Callback returns both SQL outcomes without exposing credentials.
     */
    public function concurrentClaim(Closure $raceOwnedClaims): void
    {
        ($this->verifyOwned)();
        $this->check(! DB::table('public.proof_scan_jobs')->exists(), 'fresh_worker_fixture_required');
        $bytes = AcademicProofScanner::fixtureBytes('clean');
        $jobs = [($this->submitSynthetic)($bytes), ($this->submitSynthetic)($bytes)];
        $financial = $this->financialFingerprint();
        $outcomes = $raceOwnedClaims($jobs);
        ($this->verifyOwned)();
        $wins = array_filter($outcomes, static fn (array $o) => $o['status'] === 'committed');
        $losses = array_filter($outcomes, static fn (array $o) => $o['status'] === 'rejected'
            && $o['sqlstate'] === '23505' && $o['rule'] === 'psa_runtime_active_uq');
        ($this->record)('race_outcomes', $outcomes);
        foreach ($jobs as $job) {
            ($this->record)('final', $this->state($job));
        }
        $this->check(count($wins) === 1 && count($losses) === 1
            && DB::table('public.proof_scan_attempts')->whereIn('job_id', $jobs)->count() === 1, 'concurrent_claim_quota_not_enforced');
        $this->assertFinancial($financial);
    }

    private function assertDeniedEnvironment(AcademicProofScanWorker $worker, string $case): void
    {
        $key = $case === 'missing_flag' ? AcademicProofScanWorker::FLAG : 'SILANSIA_TEST_IDENTITY';
        $saved = getenv($key);
        $before = $this->financialFingerprint();
        putenv($case === 'invalid_identity' ? $key.'={}' : $key);
        try {
            $result = $this->tick($worker);
            $this->check(in_array($result['status'], ['gate_closed', 'unavailable'], true), 'worker_accepted_invalid_environment');
        } finally {
            putenv($saved === false ? $key : $key.'='.$saved);
        }
        ($this->verifyOwned)();
        $this->check(! DB::table('public.proof_scan_attempts')->exists(), 'gate_wrote_data');
        ($this->record)('gate_graph', ['attempts' => 0]);
        $this->assertFinancial($before);
    }

    private function assertHeld(AcademicProofScanWorker $worker, string $job): void
    {
        ($this->setAdmission)('held');
        $this->check($this->tick($worker)['status'] === 'head_unavailable'
            && $this->job($job)->state === 'READY' && ! $this->attempts($job)->exists(), 'held_head_admitted_work');
    }

    private function assertInvalidDescriptor(AcademicProofScanWorker $worker, string $job): void
    {
        $this->check($this->tick($worker)['status'] === 'adapter_failed', 'unlisted_bytes_were_accepted');
        $attempt = $this->attempts($job)->first();
        $this->check($attempt?->state === 'FAILED' && $attempt->failure_code === 'adapter_failed'
            && $this->job($job)->state === 'RETRY_WAIT' && $this->verdictCount($job) === 0, 'invalid_descriptor_state');
    }

    private function assertPositive(AcademicProofScanWorker $worker, string $job, string $verdict): void
    {
        $phases = [];
        $this->observe(function (Dispatcher $events) use ($job, &$phases): void {
            $events->listen(TransactionCommitted::class, function () use ($job, &$phases): void {
                $this->check(DB::transactionLevel() === 0, 'worker_phase_not_committed');
                $phases[] = $this->attempts($job)->value('state');
            });
        }, fn () => $this->assertAccepted($worker, $verdict));
        $this->check($phases === ['LEASED', 'RUNNING', 'ACCEPTED'], 'worker_commit_order');
        ($this->record)('post_commit_states', $phases);
        $attempt = $this->attempts($job)->first();
        $seconds = DB::scalar('SELECT extract(epoch FROM lease_deadline-leased_at)::int FROM public.proof_scan_attempts WHERE id=?', [$attempt->id]);
        $q = $this->subject($job);
        $this->check($seconds === 30 && (int) $attempt->fence === 1 && (int) $attempt->generation === 1
            && (int) $attempt->identity_epoch === 1 && $this->verdictCount($job) === 1
            && $q->state === ($verdict === 'clean' ? 'CLEAN_ELIGIBLE' : 'INFECTED_HELD')
            && $q->security_hold === ($verdict === 'infected'), 'verdict_projection');
    }

    private function assertUnknownVerdict(AcademicProofScanWorker $worker, string $job): void
    {
        $this->assertAccepted($worker, 'unknown');
        $this->check($this->job($job)->state === 'RETRY_WAIT', 'first_unknown_not_retryable');
        ($this->record)('retry_checkpoint', $this->state($job));
        $this->waitForDatabaseClock('SELECT not_before<=clock_timestamp() FROM public.proof_scan_jobs WHERE id=?', $job, 4);
        $this->assertAccepted($worker, 'unknown');
        $j = $this->job($job);
        $this->check($j->state === 'DEAD_LETTER' && (int) $j->attempt_count === 2
            && $this->verdictCount($job) === 2 && $this->tick($worker)['status'] === 'idle', 'attempt_limit_or_retry_policy');
    }

    private function assertHoldAfterRescan(AcademicProofScanWorker $worker, string $job): void
    {
        $this->assertAccepted($worker, 'infected');
        $q = $this->subject($job);
        ($this->record)('pre_rescan', $this->state($job));
        ($this->verifyOwned)();
        DB::transaction(fn () => DB::scalar(
            "SELECT public.proof_scan_rescan_or_dispose(?::uuid, ?::bigint, 'rescan', public.proof_scan_authority())",
            [$q->id, $q->state_version]), 1);
        // Fixed bytes map to infected again. M10's clean-after-hold control is defined separately below.
        $this->assertAccepted($worker, 'infected');
        $next = $this->subject($job);
        $nextJob = DB::table('public.proof_scan_jobs')->where('subject_id', $q->id)->where('generation', $next->generation)->value('id');
        ($this->record)('post_rescan', $this->state($nextJob));
        $this->check($next->security_hold === true && $next->state === 'INFECTED_HELD'
            && (int) $next->generation === (int) $q->generation + 1 && $this->verdictCount($job) === 1
            && $this->verdictCount($nextJob) === 1, 'rescan_lost_hold_or_history');
    }

    private function assertInterrupted(AcademicProofScanWorker $worker, string $job, int $commit, string $state): void
    {
        $this->observe(fn (Dispatcher $events) => $this->interruptAt($events, TransactionCommitted::class, $commit),
            fn () => $this->check($this->tick($worker)['status'] === 'commit_unknown', 'missing_interruption'));
        ($this->verifyOwned)();
        $attempt = $this->attempts($job)->first();
        $this->check($attempt?->state === $state && $this->tick($worker)['status'] === 'busy', 'resumed_active_attempt');
        ($this->record)('restart_checkpoint', $this->state($job));
        $this->waitForDatabaseClock('SELECT lease_deadline<=clock_timestamp() FROM public.proof_scan_attempts WHERE id=?', $attempt->id, 32);
        $this->check($this->tick($worker)['status'] === 'expired'
            && $this->job($job)->state === 'RETRY_WAIT'
            && $this->attempts($job)->count() === 1, 'reaper_claimed_or_retried_in_same_cycle');
        ($this->record)('expiry_checkpoint', $this->state($job));
        $this->waitForDatabaseClock('SELECT not_before<=clock_timestamp() FROM public.proof_scan_jobs WHERE id=?', $job, 4);
        $this->assertAccepted($worker, 'clean');
        $this->check($this->attempts($job)->count() === 2 && $this->verdictCount($job) === 1, 'restart_duplicated_result');
    }

    private function assertResultFault(AcademicProofScanWorker $worker, string $job, string $case): void
    {
        $durable = $case === 'result_after_commit';
        $oldBackend = DB::scalar('SELECT pg_backend_pid()');
        $envelope = null;
        $this->observe(function (Dispatcher $events) use ($durable, &$envelope): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$envelope): void {
                if ($event->sql === 'SELECT public.proof_scan_accept_result(?::jsonb, ?)') {
                    $envelope = json_decode($event->bindings[0], true, flags: JSON_THROW_ON_ERROR);
                }
            });
            $this->interruptAt($events, $durable ? TransactionCommitted::class : TransactionCommitting::class, 3);
        }, fn () => $this->check($this->tick($worker)['status'] === 'commit_unknown', 'result_fault_unobserved'));
        ($this->verifyOwned)();
        $newBackend = DB::scalar('SELECT pg_backend_pid()');
        ($this->record)('result_fault_checkpoint', ['before_pid' => $oldBackend, 'after_pid' => $newBackend,
            'conclusive_fixture_commit' => $durable, 'graph' => $this->state($job)]);
        $this->check($newBackend !== $oldBackend
            && is_array($envelope) && $this->verdictCount($job) === ($durable ? 1 : 0), 'result_commit_durability');
        if (! $durable) {
            $this->check($this->attempts($job)->value('state') === 'RUNNING'
                && $this->tick($worker)['status'] === 'busy', 'result_abort_left_partial_graph');
            if ($case === 'result_before_commit') {
                return;
            }
            if ($case === 'expired_envelope') {
                $this->waitForDatabaseClock('SELECT lease_deadline<=clock_timestamp() FROM public.proof_scan_attempts WHERE id=?',
                    $envelope['attempt_id'], 32);
                $this->assertSqlRejected($envelope, 'pscan_lease');
                $this->check($this->tick($worker)['status'] === 'expired' && $this->job($job)->state === 'RETRY_WAIT'
                    && $this->verdictCount($job) === 0, 'expired_envelope_mutated_verdict');

                return;
            }
            foreach (['fence', 'generation', 'identity_epoch'] as $field) {
                $bad = [...$envelope, $field => $envelope[$field] + 1];
                $this->assertSqlRejected($bad, 'pscan_result_binding');
            }
            $this->check($this->verdictCount($job) === 0 && $this->accept($envelope)['historical_ack'] === false, 'partial_or_missing_acceptance');
        }
        $audit = DB::table('public.proof_scan_audit_events')->count();
        $result = $this->accept($envelope);
        ($this->record)('historical_ack', ['historical_ack' => $result['historical_ack'], 'is_simulation' => $result['is_simulation']]);
        $this->check($result['historical_ack'] === true && $result['is_simulation'] === true
            && $this->tick($worker)['status'] === 'idle', 'accepted_result_not_historical');
        $this->assertSqlRejected([...$envelope, 'verdict' => 'infected', 'reason_code' => 'simulation_infected'], 'pscan_result_conflict');
        $this->check($this->verdictCount($job) === 1 && DB::table('public.proof_scan_audit_events')->count() === $audit, 'duplicate_verdict_or_audit');
    }

    private function interruptAt(Dispatcher $events, string $event, int $which): void
    {
        $seen = 0;
        $events->listen($event, static function () use ($which, &$seen): void {
            if (++$seen === $which) {
                throw new RuntimeException('synthetic_worker_interruption');
            }
        });
    }

    private function observe(Closure $configure, Closure $run): void
    {
        $db = DB::connection();
        $previous = $db->getEventDispatcher();
        $observer = new Dispatcher(app());
        $configure($observer);
        $db->setEventDispatcher($observer);
        try {
            $run();
        } finally {
            $previous === null ? $db->unsetEventDispatcher() : $db->setEventDispatcher($previous);
        }
    }

    private function accept(array $envelope): array
    {
        ($this->verifyOwned)();
        return DB::transaction(fn () => json_decode(DB::scalar(
            'SELECT public.proof_scan_accept_result(?::jsonb, public.proof_scan_authority())',
            [json_encode($envelope, JSON_THROW_ON_ERROR)]), true, flags: JSON_THROW_ON_ERROR), 1);
    }

    private function assertSqlRejected(array $envelope, string $rule): void
    {
        try {
            $this->accept($envelope);
        } catch (QueryException $error) {
            $this->check(($error->errorInfo[0] ?? null) === '23514'
                && str_contains($error->errorInfo[2] ?? '', 'constraint "'.$rule.'"'), 'unrelated_sql_rejection');
            ($this->record)('sql_rejections', ['sqlstate' => '23514', 'constraint' => $rule]);

            return;
        }
        throw new RuntimeException('expected_scanner_constraint');
    }

    private function assertAccepted(AcademicProofScanWorker $worker, string $verdict): void
    {
        $result = $this->tick($worker);
        $this->check($result['status'] === 'accepted' && $result['verdict'] === $verdict
            && $result['historical_ack'] === false, 'worker_acceptance_expected');
    }

    private function waitForDatabaseClock(string $query, string $id, int $maxSeconds): void
    {
        $guardDeadline = hrtime(true) + $maxSeconds * 1_000_000_000;
        do {
            if (DB::scalar($query, [$id]) === true) {
                return;
            }
            usleep(50_000);
        } while (hrtime(true) < $guardDeadline);
        throw new RuntimeException('fixture_database_clock_wait_timeout');
    }

    private function job(string $id): object
    {
        return DB::table('public.proof_scan_jobs')->where('id', $id)->first();
    }

    private function subject(string $job): object
    {
        return DB::table('public.proof_scan_subjects')->where('id', $this->job($job)->subject_id)->first();
    }

    private function attempts(string $job): \Illuminate\Database\Query\Builder
    {
        return DB::table('public.proof_scan_attempts')->where('job_id', $job);
    }

    private function verdictCount(string $job): int
    {
        return DB::table('public.proof_scan_verdicts')->where('job_id', $job)->count();
    }

    private function financialFingerprint(): array
    {
        $rows = [];
        foreach (['users', 'elderlies', 'service_classes', 'service_enrollments', 'financial_ownership_periods',
            'invoices', 'invoice_revisions', 'payments', 'billing_requests', 'billing_actions'] as $table) {
            $rows[$table] = DB::scalar("SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]'::jsonb)::text) FROM public.{$table} t");
        }

        return $rows;
    }

    private function check(bool $condition, string $kind): void
    {
        if (! $condition) {
            throw new RuntimeException($kind);
        }
    }

    private function state(string $job): array
    {
        $q = $this->subject($job);
        $j = $this->job($job);
        return ['subject_state' => $q->state, 'generation' => $q->generation, 'security_hold' => $q->security_hold,
            'job_state' => $j->state, 'job_generation' => $j->generation, 'not_before' => $j->not_before,
            'attempt_count' => $j->attempt_count, 'attempts' => $this->attempts($job)->orderBy('attempt_no')->get([
                'attempt_no', 'state', 'generation', 'fence', 'identity_epoch', 'leased_at', 'lease_deadline', 'started_at', 'completed_at', 'failure_code',
            ])->all(), 'verdicts' => DB::table('public.proof_scan_verdicts')->where('job_id', $job)
                ->orderBy('received_at')->get(['verdict', 'reason_code', 'generation', 'fence'])->all(),
            'audit' => DB::table('public.proof_scan_audit_events')->where('subject_id', $q->id)->orderBy('recorded_at')
                ->get(['event_type', 'recorded_at'])->all()];
    }

    private function tick(AcademicProofScanWorker $worker): array
    {
        $result = ($this->invokeCommand)();
        ($this->record)('ticks', $result);

        return $result;
    }

    private function assertFinancial(array $before): void
    {
        $after = $this->financialFingerprint();
        ($this->record)('financial', ['before' => $before, 'after' => $after, 'equal' => $before === $after]);
        $this->check($before === $after, 'scanner_wrote_financial_data');
    }
}
