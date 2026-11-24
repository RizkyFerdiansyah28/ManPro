<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$visible = static function (array $f, string $kind) use ($reader): array {
    return $reader(static function ($observer, int $primaryPid, int $observerPid) use ($f, $kind): array {
        $audit = $observer->table('proof_scan_audit_events')->where('subject_id', $f['subject'])->where('event_type', $kind)->count();
        $payment = $observer->table('payments')->where('id', $f['payment'])->value('status');
        $invoice = $observer->table('invoices')->where('id', $f['invoice'])->value('status');
        $fact = $observer->table('billing_requests')->where('outcome_payment_id', $f['payment'])->where('operation', 'verify')->count();
        $action = $observer->table('billing_actions')->where('payment_id', $f['payment'])->where('action', 'payment_verified')->count();

        return ['audit' => $audit, 'payment' => $payment, 'invoice' => $invoice, 'verify_facts' => $fact,
            'verify_actions' => $action, 'primary_pid' => $primaryPid, 'observer_pid' => $observerPid];
    });
};
$denialResponse = static function ($response, string $kind) use ($body, $assert): array {
    $assert($response->getStatusCode() === 503 && ($body($response)['error']['code'] ?? null) === $kind
        && ! $response->headers->has('Content-Disposition')
        && ! str_starts_with((string) $response->headers->get('Content-Type'), 'image/'), 'fault_must_deny_without_proof');

    return ['http' => 503, 'proof_bytes' => 0, 'kind' => $kind];
};
$test('T02', static function () use ($newProof, $login, $request, $observe, $visible, $denialResponse, $facts, $assert): array {
    $results = [];
    foreach (['insert','deferred'] as $kind) {
        [$g, $f] = $newProof();
        $family = $login($g['p']['family']);
        $before = $facts($f);
        $injected = 0;
        $appendReturned = 0;
        $response = $observe(static function ($events) use ($kind, $f, &$injected, &$appendReturned): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($kind, $f, &$injected, &$appendReturned): void {
                if ($event->connection->transactionLevel() !== 1) {
                    return;
                }
                if (str_contains($event->sql, 'SELECT public.proof_scan_append_audit(')) {
                    $appendReturned++;
                    if ($kind === 'deferred' && $injected++ === 0) {
                        // Same invalid next-generation graph as the existing E12 control.
                        $q = DB::table('proof_scan_subjects')->where('id', $f['subject'])->first();
                        $j = (array) DB::table('proof_scan_jobs')->where('subject_id', $q->id)->where('generation', $q->generation)->first();
                        $j['id'] = (string) Str::uuid();
                        $j['generation'] = $q->generation + 1;
                        $j['state'] = 'READY';
                        $j['attempt_count'] = 0;
                        $j['current_attempt_id'] = null;
                        $j['fence'] = 1;
                        $j['terminal_reason'] = null;
                        $j['origin'] = 'authorized_rescan';
                        $j['requested_by_function'] = 'proof_scan_rescan_or_dispose';
                        DB::table('proof_scan_jobs')->insert($j);
                    }
                } elseif ($kind === 'insert' && $event->sql === 'SELECT public.proof_scan_authority()' && $injected++ === 0) {
                    // Test-only object lives in the failing request transaction and rolls back with it.
                    DB::unprepared(<<<'SQL'
                        CREATE FUNCTION public.fixture_retest_deny_audit() RETURNS trigger
                        LANGUAGE plpgsql AS $body$
                        BEGIN
                            RAISE EXCEPTION 'fixture audit failure' USING ERRCODE='23514', CONSTRAINT='fixture_deny_audit_insert';
                        END;
                        $body$;
                        CREATE TRIGGER fixture_retest_deny_audit BEFORE INSERT ON public.proof_scan_audit_events
                        FOR EACH ROW EXECUTE FUNCTION public.fixture_retest_deny_audit()
                        SQL);
                }
            });
        }, static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); });
        $proof = $visible($f, 'protected_get_granted');
        $assert($injected === 1 && $appendReturned === ($kind === 'deferred' ? 1 : 0) && $proof['audit'] === 0
            && $facts($f) == $before
            && DB::scalar("SELECT to_regprocedure('public.fixture_retest_deny_audit()')") === null, 'audit_fault_rollback_not_proven');
        $results[$kind] = [...$denialResponse($response, 'proof_use_unavailable'), 'append_returned' => $appendReturned,
            'fixture_rolled_back' => true, 'committed_audit' => $proof['audit']];
    }

    return $results;
});
$test('T03', static function () use ($newProof, $login, $request, $observe, $denialResponse, $facts, $visible, $assert): array {
    $variants = [];
    foreach (['before_durable' => TransactionCommitting::class, 'after_durable_before_ack' => TransactionCommitted::class] as $label => $eventClass) {
        [$g, $f] = $newProof();
        $family = $login($g['p']['family']);
        $before = $facts($f);
        $commitAttempts = 0;
        $appendAttempts = 0;
        $response = $observe(static function ($events) use ($eventClass, &$commitAttempts, &$appendAttempts): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$appendAttempts): void {
                if (str_contains($event->sql, 'SELECT public.proof_scan_append_audit(')) {
                    $appendAttempts++;
                }
            });
            $events->listen($eventClass, static function () use (&$commitAttempts): void {
                $commitAttempts++;
                throw new RuntimeException('synthetic_commit_ack_fault');
            });
        }, static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); });
        $observed = $visible($f, 'protected_get_granted');
        $expected = $label === 'before_durable' ? 0 : 1;
        $assert($commitAttempts === 1 && $appendAttempts === 1 && $observed['audit'] === $expected
            && $facts($f) == $before, 'commit_unknown_order_or_retry_mismatch');
        $variants[$label] = [...$denialResponse($response, 'proof_use_unavailable'), ...$observed,
            'append_attempts' => $appendAttempts, 'commit_attempts' => $commitAttempts, 'finance_unchanged' => true];
    }

    return $variants;
});

$test('V02', static function () use ($newProof, $login, $request, $observe, $visible, $denialResponse, $verifyPayload, $assert, &$record): array {
    $same = $record['access']['cases']['V02'] ?? [];
    $assert(($same['same_transaction_id'] ?? null) === true, 'verify_same_commit_control_required');
    $faults = [];
    foreach (['after_append', 'after_financial_update', 'before_durable', 'after_durable_before_ack'] as $label) {
        [$g, $f] = $newProof();
        $admin = $login($g['p']['admin']);
        $payload = $verifyPayload($admin, $f);
        $injections = 0;
        $appendAttempts = 0;
        $response = $observe(static function ($events) use ($label, &$injections, &$appendAttempts): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($label, &$injections, &$appendAttempts): void {
                $append = str_contains($event->sql, 'SELECT public.proof_scan_append_audit(');
                if ($append) {
                    $appendAttempts++;
                }
                if (($append && $label === 'after_append')
                    || ($label === 'after_financial_update' && str_starts_with($event->sql, 'update "public"."invoices"'))) {
                    $injections++;
                    throw new RuntimeException('synthetic_verify_update_fault');
                }
            });
            if (str_contains($label, 'durable')) {
                $events->listen($label === 'before_durable' ? TransactionCommitting::class : TransactionCommitted::class,
                    static function () use (&$injections): void {
                        $injections++;
                        throw new RuntimeException('synthetic_verify_commit_ack_fault');
                    });
            }
        }, static function () use ($request, &$admin, $f, $payload) {
            return $request($admin, 'POST', $f['admin_path'].'/verify', $payload);
        });
        $proof = $visible($f, 'verify_use_granted');
        $durable = $label === 'after_durable_before_ack';
        $expected = match ($label) {
            'after_append' => 'proof_use_unavailable',
            'after_financial_update' => 'infrastructure_failure',
            default => 'unknown_commit_outcome',
        };
        $assert($injections === 1 && $appendAttempts === 1 && $proof['audit'] === (int) $durable
            && $proof['verify_facts'] === (int) $durable && $proof['verify_actions'] === (int) $durable
            && $proof['payment'] === ($durable ? 'verified' : 'pending') && $proof['invoice'] === ($durable ? 'paid' : 'unpaid'),
            'verify_fault_atomicity_not_proven');
        $faults[$label] = [...$denialResponse($response, $expected), ...$proof, 'append_attempts' => $appendAttempts];
    }

    return ['same_transaction_id' => true, 'positive_visibility' => $same, 'faults' => $faults];
});

$test('V01', static function () use ($legacyProof, $newProof, $login, $request, $verifyPayload, $scan, $rescan, $visible, $denialResponse, $assert, &$record): array {
    $previous = $record['access']['cases']['V01'] ?? [];
    $assert(($previous['held_replay'] ?? null) === true, 'verify_positive_and_replay_control_missing');
    $results = [];
    foreach (['missing', 'queued', 'unknown', 'infected', 'stale'] as $scenario) {
        if ($scenario === 'missing') {
            $g = $legacyProof;
            $invoice = $g['invoice']['id'];
            $payment = $g['payment']['id'];
            $f = ['payment' => $payment, 'subject' => null, 'invoice' => $invoice,
                'admin_path' => "/admin/billing/invoices/{$invoice}/payments/{$payment}"];
        } else {
            [$g, $f] = $newProof(match ($scenario) {
                'unknown', 'infected' => $scenario,
                default => 'clean',
            }, $scenario !== 'queued');
            if ($scenario === 'stale') {
                $rescan($f);
            }
        }
        $admin = $login($g['p']['admin']);
        $payload = $verifyPayload($admin, $f);
        $response = $request($admin, 'POST', $f['admin_path'].'/verify', $payload);
        $proof = $visible($f, 'verify_use_granted');
        $assert($proof['audit'] === 0 && $proof['payment'] === 'pending' && $proof['invoice'] === 'unpaid'
            && $proof['verify_facts'] === 0 && $proof['verify_actions'] === 0, 'verify_guard_mutated_finance');
        $results[$scenario] = [...$denialResponse($response, 'proof_use_unavailable'), ...$proof];
        if (in_array($scenario, ['queued', 'stale'], true)) {
            $scan($f, 'clean'); // Drain fixture before the next one-shot.
        }
        if ($scenario === 'unknown') {
            usleep(1100000);
            $scan($f, 'unknown'); // Finish owned retry queue.
        }
    }
    [$g, $f] = $newProof();
    app()->instance('request', Request::create('http://127.0.0.1/', 'GET'));
    Auth::forgetGuards();
    Auth::guard('web')->setUser(User::findOrFail($g['p']['admin']));
    $writer = app(PaymentService::class);
    $state = $writer->reviewState($g['p']['admin'], $f['invoice'], $f['payment']);
    try {
        $writer->verify(new PaymentCommand(['operation' => 'verify', 'actor_id' => $g['p']['admin'], 'invoice_id' => $f['invoice'],
            'revision_id' => $g['revision']['id'], 'payment_id' => $f['payment'], 'request_id' => (string) Str::uuid(),
            'expected_state' => $state, 'bank_receipt_checked' => true, 'confirm' => true]));
        throw new RuntimeException('direct_writer_no_session_expected_denial');
    } catch (PaymentFailure $error) {
        $assert($error->kind === 'proof_use_unavailable', 'direct_writer_no_session_wrong_denial');
    } finally {
        Auth::forgetGuards();
    }
    $direct = $visible($f, 'verify_use_granted');
    $assert($direct['audit'] === 0 && $direct['payment'] === 'pending' && $direct['invoice'] === 'unpaid', 'direct_verify_mutated_finance');

    return ['positive_and_replay' => $previous, 'denials' => $results, 'direct_no_session' => $direct];
});
