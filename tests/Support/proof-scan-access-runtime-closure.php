<?php

declare(strict_types=1);

use App\Services\Billing\AcademicProofScanner;
use Illuminate\Support\Facades\DB;

$currentGraph = static function (array $fixture): array {
    $subject = DB::table('proof_scan_subjects')->where('id', $fixture['subject'])->sole();
    $job = DB::table('proof_scan_jobs')->where('subject_id', $subject->id)
        ->where('generation', $subject->generation)->sole();
    $attempt = $job->current_attempt_id === null ? null
        : DB::table('proof_scan_attempts')->where('id', $job->current_attempt_id)->sole();

    return [
        'subject_state' => $subject->state,
        'generation' => (int) $subject->generation,
        'state_version' => (int) $subject->state_version,
        'security_hold' => $subject->security_hold,
        'hold_reason' => $subject->hold_reason,
        'job_state' => $job->state,
        'attempt_state' => $attempt?->state,
        'fence' => (int) $job->fence,
        'identity_epoch' => $attempt === null ? null : (int) $attempt->identity_epoch,
        'verdicts' => DB::table('proof_scan_verdicts')->where('subject_id', $subject->id)
            ->orderBy('generation')->orderBy('received_at')
            ->get(['generation', 'fence', 'identity_epoch', 'verdict', 'reason_code'])->all(),
    ];
};
$claim = static function (array $fixture) use ($owned, $assert): array {
    $owned();
    $assignment = DB::transaction(static function () use ($fixture): array {
        $job = DB::table('proof_scan_jobs')->where('subject_id', $fixture['subject'])->orderByDesc('generation')->first();
        return json_decode(DB::scalar('SELECT public.proof_scan_claim_attempt(?::uuid, 1,
            public.proof_scan_authority(), public.proof_scan_authority())', [$job->id]), true, flags: JSON_THROW_ON_ERROR);
    }, 1);
    $assert(($assignment['is_simulation'] ?? null) === true && isset($assignment['attempt_id']), 'closure_owned_assignment_required');
    $owned();
    DB::transaction(static fn () => DB::select("SELECT public.proof_scan_start_or_expire_attempt(?::uuid, 'start', public.proof_scan_authority())",
        [$assignment['attempt_id']]), 1);

    return $assignment;
};
$accept = static function (array $fixtureEnvelope) use ($owned): array {
    $owned();

    return DB::transaction(static fn () => json_decode(DB::scalar(
        'SELECT public.proof_scan_accept_result(?::jsonb, public.proof_scan_authority())',
        [json_encode($fixtureEnvelope, JSON_THROW_ON_ERROR)]
    ), true, flags: JSON_THROW_ON_ERROR), 1);
};
$financialState = static function (array $fixture) use ($grants): array {
    return ['payment' => DB::table('payments')->where('id', $fixture['payment'])->value('status'),
        'invoice' => DB::table('invoices')->where('id', $fixture['invoice'])->value('status'),
        'get_grants' => $grants($fixture),
        'verify_grants' => $grants($fixture, 'verify_use_granted'),
        'verify_facts' => DB::table('billing_requests')->where('outcome_payment_id', $fixture['payment'])->where('operation', 'verify')->count(),
        'verify_actions' => DB::table('billing_actions')->where('payment_id', $fixture['payment'])->where('action', 'payment_verified')->count()];
};
$deniedUse = static function (array $graph, array $fixture) use (
    $login, $deny, $request, $verifyPayload, $denialResponse, $currentGraph, $accessFingerprint, $financialState, $assert
): array {
    $family = $login($graph['p']['family']);
    $admin = $login($graph['p']['admin']);
    $initial = $financialState($fixture);
    $scannerBefore = $currentGraph($fixture);
    $hash = $accessFingerprint($fixture);
    $assert($initial['payment'] === 'pending' && $initial['invoice'] === 'unpaid'
        && $initial['verify_grants'] === 0 && $initial['verify_facts'] === 0 && $initial['verify_actions'] === 0,
        'closure_pending_fixture_required');
    $getFamily = $deny($family, $fixture, 503);
    $getAdmin = $deny($admin, $fixture, 503);
    $payload = $verifyPayload($admin, $fixture);
    $post = $request($admin, 'POST', $fixture['admin_path'].'/verify', $payload);
    $verify = $denialResponse($post, 'proof_use_unavailable');
    $final = $financialState($fixture);
    $assert($initial === $final && $scannerBefore == $currentGraph($fixture) && hash_equals($hash, $accessFingerprint($fixture)),
        'closure_denial_changed_data');

    return ['family_get' => $getFamily, 'admin_get' => $getAdmin,
        'admin_verify' => [...$verify, 'audit_delta' => 0, 'verify_facts_delta' => 0, 'verify_actions_delta' => 0],
        'before' => $initial, 'after' => $final, 'financial_scanner_and_file_unchanged' => true];
};

$test('S02', static function () use ($newProof, $rescan, $claim, $envelope, $accept, $currentGraph, $deniedUse, $assert, &$record): array {
    $repeatInfected = $record['access']['cases']['S02'] ?? [];
    [$graph, $fixture] = $newProof('infected');
    $historical = $currentGraph($fixture);
    $assert($historical['security_hold'] === true && $historical['subject_state'] === 'INFECTED_HELD'
        && count($historical['verdicts']) === 1 && $historical['verdicts'][0]->verdict === 'infected', 'closure_historical_infected_required');
    $rescan($fixture);
    $assignment = $claim($fixture);
    // Approved D07 test-only synthetic input. Keep the exact immutable descriptor from claim.
    // The real one-shot above produced INFECTED. This new-generation fixture is not its output.
    $result = $accept($envelope($assignment, 'clean'));
    $after = $currentGraph($fixture);
    $assert(($result['is_simulation'] ?? false) === true && ($result['historical_ack'] ?? true) === false
        && $after['generation'] === $historical['generation'] + 1 && $after['subject_state'] === 'INFECTED_HELD'
        && $after['security_hold'] === true && $after['hold_reason'] === 'infected'
        && $after['job_state'] === 'COMPLETED' && $after['attempt_state'] === 'ACCEPTED'
        && count($after['verdicts']) === 2 && $after['verdicts'][0] == $historical['verdicts'][0]
        && $after['verdicts'][1]->verdict === 'clean' && (int) $after['verdicts'][1]->generation === $after['generation'],
        'closure_clean_with_persistent_hold_not_proven');

    return ['negative_control_repeated_infected' => $repeatInfected, 'fixture_protocol' => 'IMP-R2-01 D07, approved SQL result routine',
        'history' => $historical, 'current' => $after, 'denied_use' => $deniedUse($graph, $fixture)];
});

$test('S03', static function () use (
    $newProof, $rescan, $claim, $envelope, $accept, $currentGraph, $deniedUse, $login, $assertCleanGet, $grants, $assert, &$record
): array {
    $expiry = $record['access']['cases']['S03'] ?? [];
    $assert(($expiry['actual_expiry']['http'] ?? null) === 503 && ($expiry['stale_generation_ordered'] ?? false),
        'closure_expiry_or_generation_control_missing');
    $variants = [];
    foreach (['fence', 'scanner_identity', 'identity_epoch'] as $field) {
        [$graph, $fixture] = $newProof();
        $family = $login($graph['p']['family']);
        $assertCleanGet($family, $fixture);
        $rescan($fixture);
        $assignment = $claim($fixture);
        $candidate = $envelope($assignment, app(AcademicProofScanner::class)->scan($assignment));
        $assert($candidate['verdict'] === 'clean', 'closure_clean_candidate_required');
        $changedValue = $field === 'scanner_identity' ? 'fixture_identity_mismatch' : $candidate[$field] + 1;
        $invalid = [...$candidate, $field => $changedValue];
        $before = $currentGraph($fixture);
        try {
            $accept($invalid);
            throw new RuntimeException('closure_invalid_result_accepted');
        } catch (PDOException $error) {
            $state = $error->errorInfo[0] ?? null;
            $matched = str_contains($error->errorInfo[2] ?? '', 'constraint "pscan_result_binding"');
            $assert($state === '23514' && $matched, 'closure_result_wrong_rejection');
        }
        $rejected = $currentGraph($fixture);
        $assert($rejected == $before && $rejected['subject_state'] === 'SCANNING' && $rejected['attempt_state'] === 'RUNNING'
            && count($rejected['verdicts']) === 1 && (int) $rejected['verdicts'][0]->generation < $rejected['generation'],
            'closure_rejected_result_changed_current_graph');
        $denials = $deniedUse($graph, $fixture);
        $valid = $accept($candidate);
        $control = $currentGraph($fixture);
        $assert(($valid['historical_ack'] ?? true) === false && $control['subject_state'] === 'CLEAN_ELIGIBLE'
            && $control['attempt_state'] === 'ACCEPTED' && count($control['verdicts']) === 2, 'closure_valid_exact_result_control_missing');
        $count = $grants($fixture);
        $assertCleanGet($family, $fixture);
        $assert($grants($fixture) === $count + 1, 'closure_valid_exact_grant_missing');
        $variants[$field] = ['field_changed' => $field, 'expected' => $candidate[$field], 'submitted' => $changedValue,
            'sqlstate' => $state, 'rule' => 'pscan_result_binding', 'graph_after_rejection' => $rejected, 'denied_use' => $denials,
            'exact_control' => ['http' => 200, 'proof_bytes' => strlen($fixture['bytes']), 'audit_delta' => 1, 'graph' => $control]];
    }

    return ['expiry_and_generation_controls' => $expiry, 'invalid_fence' => $variants['fence'],
        'invalid_identity' => ['name' => $variants['scanner_identity'], 'epoch' => $variants['identity_epoch']],
        'invalid_verdict_persisted' => false];
});
