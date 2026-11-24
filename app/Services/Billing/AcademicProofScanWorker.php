<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AcademicProofScanWorker
{
    public const FLAG = 'SILANSIA_ACADEMIC_PROOF_SCAN';

    private const CONNECTION = 'silansia_testing';

    // One trusted synthetic identity in this owned run. The routine binds it to each attempt.
    private const IDENTITY_EPOCH = 1;

    public function __construct(private readonly AcademicProofScanner $scanner) {}

    /** @return array{status:string,phase:string,verdict?:string,historical_ack?:bool} */
    public function run(): array
    {
        $phase = 'claim';
        try {
            $claim = $this->transition($this->claimOrExpire(...));
            if ($claim['status'] !== 'claimed') {
                return ['status' => $claim['status'], 'phase' => $phase];
            }
            $assignment = $claim['assignment'];
            $attempt = $assignment['attempt_id'];

            $phase = 'start';
            $started = $this->transition(fn (Connection $db, string $authority) => $this->startOrExpire($db, $authority, $attempt));
            if (! $started) {
                return ['status' => 'expired', 'phase' => $phase];
            }

            // No database transaction or file access while running the synthetic adapter.
            $phase = 'adapter';
            $start = hrtime(true);
            try {
                $verdict = $this->scanner->scan($assignment);
                if (hrtime(true) - $start >= 5_000_000_000) {
                    throw new RuntimeException('academic_adapter_timeout');
                }
            } catch (Throwable) {
                $status = $this->transition(function (Connection $db, string $authority) use ($attempt): string {
                    $expired = $this->expired($db, $attempt);
                    $this->attemptTransition($db, $authority, $attempt, $expired ? 'expire' : 'fail');

                    return $expired ? 'expired' : 'adapter_failed';
                });

                return ['status' => $status, 'phase' => $phase];
            }

            $phase = 'result';
            $result = $this->transition(fn (Connection $db, string $authority) => $this->accept($db, $authority, $assignment, $verdict));

            return [...$result, 'phase' => $phase];
        } catch (PDOException $error) {
            // The lease may cross its deadline between discovery and the routine's locked check.
            if (isset($attempt) && $this->constraint($error, '23514', 'pscan_lease')) {
                try {
                    $expired = $this->transition(function (Connection $db, string $authority) use ($attempt): bool {
                        if (! $this->expired($db, $attempt)) {
                            return false;
                        }
                        $this->attemptTransition($db, $authority, $attempt, 'expire');

                        return true;
                    });
                    if ($expired) {
                        return ['status' => 'expired', 'phase' => $phase];
                    }
                } catch (Throwable $expiryError) {
                    return ['status' => $this->failureStatus($expiryError), 'phase' => 'expire'];
                }
            }
            return ['status' => $this->failureStatus($error), 'phase' => $phase];
        } catch (Throwable $error) {
            return ['status' => $this->failureStatus($error), 'phase' => $phase];
        }
    }

    private function failureStatus(Throwable $error): string
    {
        if ($error instanceof PDOException) {
            return $this->constraint($error, '23505', 'psa_runtime_active_uq')
                || $this->constraint($error, '23505', 'psa_active_job_uq') ? 'busy' : 'transition_rejected';
        }

        return match ($error->getMessage()) {
            'academic_gate_closed' => 'gate_closed',
            'academic_commit_unknown' => 'commit_unknown',
            default => 'unavailable',
        };
    }

    private function constraint(PDOException $error, string $state, string $name): bool
    {
        return ($error->errorInfo[0] ?? null) === $state
            && str_contains($error->errorInfo[2] ?? '', 'constraint "'.$name.'"');
    }

    /** One owned SQL transaction, with no retry or continuation after uncertain outcome. */
    private function transition(Closure $operation): mixed
    {
        if (! app()->runningInConsole() || ! app()->environment('testing') || app()->configurationIsCached()
            || getenv('APP_ENV') !== 'testing' || getenv(self::FLAG) !== '1'
            || getenv('DB_CONNECTION') !== self::CONNECTION || config('database.default') !== self::CONNECTION) {
            throw new RuntimeException('academic_gate_closed');
        }

        // Resolve and prepare the pinned existing guard before opening a transaction.
        // prepare() is nonthrowing on denial. Never open a PDO ourselves after a failed preparation.
        $db = DB::connection(self::CONNECTION);
        if ($db->transactionLevel() !== 0 || $db->getDriverName() !== 'pgsql'
            || ! preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $db->getDatabaseName())) {
            throw new RuntimeException('academic_gate_closed');
        }
        $readiness = app(ProofScanReadiness::class);
        $readiness->prepare();
        if (! $db->getRawPdo() instanceof PDO) {
            throw new RuntimeException('academic_gate_closed');
        }

        $committing = false;
        try {
            $db->beginTransaction();
            $db->statement("SET LOCAL lock_timeout='5s'");
            $db->statement("SET LOCAL statement_timeout='15s'");
            $db->statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
            // This checks the exact identity, same initialized PDO, runtime root, financial pin and M10/M11.
            $readiness->assertFreshReady();
            unset($readiness);
            $authority = $db->scalar('SELECT public.proof_scan_authority()', [], false);
            $result = $operation($db, $authority);
            $committing = true;
            $db->commit();

            return $result;
        } catch (Throwable $error) {
            // Do not retain the PDO pinned by the readiness object when closing the connection.
            unset($readiness);
            try {
                if ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }
            } catch (Throwable) {
                DB::purge(self::CONNECTION);
                throw new RuntimeException('academic_commit_unknown');
            }
            if ($committing) {
                DB::purge(self::CONNECTION);
                throw new RuntimeException('academic_commit_unknown');
            }

            throw $error;
        }
    }

    private function claimOrExpire(Connection $db, string $authority): array
    {
        $head = $db->selectOne("SELECT policy_id, epoch, admission_incarnation, admission
            FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' AND authority_ref=?", [$authority], false);
        if ($head === null) {
            return ['status' => 'head_unavailable'];
        }

        // Discovery is advisory and lock-free. Each routine revalidates under P → Q → J → A.
        $active = $db->selectOne("SELECT id, lease_deadline<=clock_timestamp() AS expired
            FROM public.proof_scan_attempts WHERE admission_incarnation=? AND state IN ('LEASED','RUNNING')",
            [$head->admission_incarnation], false);
        if ($active !== null) {
            if (! $active->expired) {
                return ['status' => $head->admission === 'active' ? 'busy' : 'head_unavailable'];
            }
            $this->attemptTransition($db, $authority, $active->id, 'expire');

            return ['status' => 'expired'];
        }
        if ($head->admission !== 'active') {
            return ['status' => 'head_unavailable'];
        }

        $job = $db->scalar("SELECT j.id FROM public.proof_scan_jobs j
            JOIN public.proof_scan_subjects q ON q.id=j.subject_id
            WHERE j.policy_id=? AND j.policy_epoch=? AND j.admission_incarnation=?
              AND j.state IN ('READY','RETRY_WAIT') AND j.not_before<=clock_timestamp()
              AND j.attempt_count<2 AND j.stage_id='synthetic_stage_v1'
              AND q.state<>'RETIRED' AND (q.policy_id,q.policy_epoch,q.admission_incarnation,q.generation)
                  =(j.policy_id,j.policy_epoch,j.admission_incarnation,j.generation)
            ORDER BY j.not_before, j.id LIMIT 1", [$head->policy_id, $head->epoch, $head->admission_incarnation], false);
        if ($job === null) {
            return ['status' => 'idle'];
        }
        $assignment = $this->object($db->scalar('SELECT public.proof_scan_claim_attempt(?::uuid, ?::bigint, ?, ?)',
            [$job, self::IDENTITY_EPOCH, $authority, $authority], false));
        if (! is_string($assignment['attempt_id'] ?? null) || ! BillingTime::uuid($assignment['attempt_id'])
            || ($assignment['job_id'] ?? null) !== $job || ($assignment['identity_epoch'] ?? null) !== self::IDENTITY_EPOCH
            || ($assignment['deployment_audience'] ?? null) !== $authority) {
            throw new RuntimeException('academic_assignment_rejected');
        }

        return ['status' => 'claimed', 'assignment' => $assignment];
    }

    private function startOrExpire(Connection $db, string $authority, string $attempt): bool
    {
        $expired = $this->expired($db, $attempt);
        $this->attemptTransition($db, $authority, $attempt, $expired ? 'expire' : 'start');

        return ! $expired;
    }

    private function expired(Connection $db, string $attempt): bool
    {
        // Never reap a terminal ACCEPTED attempt, including on duplicate acceptance.
        return $db->scalar("SELECT lease_deadline<=clock_timestamp() FROM public.proof_scan_attempts
            WHERE id=? AND state IN ('LEASED','RUNNING')", [$attempt], false) === true;
    }

    private function attemptTransition(Connection $db, string $authority, string $attempt, string $mode): void
    {
        $db->selectFromWriteConnection('SELECT public.proof_scan_start_or_expire_attempt(?::uuid, ?, ?)', [$attempt, $mode, $authority]);
    }

    private function accept(Connection $db, string $authority, array $assignment, string $verdict): array
    {
        if ($this->expired($db, $assignment['attempt_id'])) {
            $this->attemptTransition($db, $authority, $assignment['attempt_id'], 'expire');

            return ['status' => 'expired'];
        }
        unset($assignment['lease_deadline'], $assignment['adapter_timeout_seconds']);
        $envelope = [...$assignment,
            'result_id' => $assignment['attempt_id'],
            'verdict' => $verdict,
            'reason_code' => 'simulation_'.$verdict,
            'observed_at' => $db->scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')", [], false),
            'engine_id' => 'synthetic_mock',
            'engine_version' => '1',
            'signature_set_id' => 'not_applicable_simulation',
            'signature_version' => 'not_applicable_simulation',
            'auth_scheme_version' => 'trusted_in_process_v1',
            'key_epoch_reference' => 'not_applicable_owned_runtime',
        ];
        $ack = $this->object($db->scalar('SELECT public.proof_scan_accept_result(?::jsonb, ?)',
            [json_encode($envelope, JSON_THROW_ON_ERROR), $authority], false));
        if (($ack['is_simulation'] ?? null) !== true || ! is_bool($ack['historical_ack'] ?? null)
            || ! is_string($ack['verdict_id'] ?? null) || ! BillingTime::uuid($ack['verdict_id'])) {
            throw new RuntimeException('academic_ack_rejected');
        }

        return ['status' => 'accepted', 'verdict' => $verdict, 'historical_ack' => $ack['historical_ack']];
    }

    private function object(string $json): array
    {
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result) || array_is_list($result)) {
            throw new RuntimeException('academic_database_response_rejected');
        }

        return $result;
    }
}
