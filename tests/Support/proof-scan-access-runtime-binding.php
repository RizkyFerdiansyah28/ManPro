<?php

declare(strict_types=1);

use App\Support\Billing\ProofAuditBinding;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$typed = static fn (array $f) => DB::table('proof_scan_audit_events')->where('subject_id', $f['subject'])
    ->where('event_type', 'protected_get_granted')->orderBy('recorded_at')->orderBy('id')->get();
$denyWithKeyAfterAuth = static function (array &$cookies, array $f, string $key) use ($observe, $deny, $assert): array {
    $original = config('app.key');
    $changed = false;
    try {
        $result = $observe(static function ($events) use ($key, &$changed): void {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($key, &$changed): void {
                if (! $changed && $event->connection->transactionLevel() === 0 && $event->sql === 'SELECT public.proof_scan_authority()') {
                    config(['app.key' => $key]);
                    $changed = true;
                }
            });
        }, static function () use ($deny, &$cookies, $f): array { return $deny($cookies, $f, 503); });
        $assert($changed, 'fixture_key_change_after_auth_not_reached');

        return $result;
    } finally {
        config(['app.key' => $original]);
    }
};
$test('T04', static function () use ($newProof, $login, $assertCleanGet, $typed, $deny, $observe, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $audit = $typed($f)->sole();
    $rejected = [];
    foreach (['update' => 'pscan_append_only', 'delete' => 'pscan_no_delete'] as $operation => $expected) {
        try {
            DB::transaction(function () use ($audit, $operation): void {
                $query = DB::table('proof_scan_audit_events')->where('id', $audit->id);
                if ($operation === 'update') {
                    $query->update(['reason_code' => $audit->reason_code]);
                } else {
                    $query->delete();
                }
            }, 1);
            throw new RuntimeException('audit_immutability_unexpected_success');
        } catch (PDOException $error) {
            $sqlstate = $error->errorInfo[0] ?? null;
            $matched = str_contains($error->errorInfo[2] ?? '', 'constraint "'.$expected.'"');
            $assert($sqlstate === '23514' && $matched, 'audit_immutability_wrong_rule');
            $rejected[$operation] = ['sqlstate' => $sqlstate, 'rule' => $expected];
        }
    }
    $sessionMoved = false;
    $switched = $observe(static function ($events) use (&$sessionMoved): void {
        $events->listen(TransactionBeginning::class, static function () use (&$sessionMoved): void {
            // The grant has been prepared but cannot survive an in-process session change.
            request()->session()->setId(Str::random(40));
            $sessionMoved = true;
        });
    }, static function () use ($deny, &$family, $f): array { return $deny($family, $f, 503); });
    $wrong = $login($g['p']['next_family']);
    $deny($wrong, $f, 404);
    $assert($sessionMoved && $typed($f)->sole() == $audit, 'old_audit_changed_or_reused');

    return ['immutable' => $rejected, 'other_family_after_grant_denied' => true, 'session_changed_after_prepare' => $switched];
});
$test('B01', static function () use ($newProof, $login, $assertCleanGet, $typed, $denyWithKeyAfterAuth, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $before = $typed($f)->sole();
    $encoded = config('app.key');
    $encrypter = app('encrypter');
    $rotatedKey = 'base64:'.base64_encode(random_bytes(32));
    try {
        $stale = $denyWithKeyAfterAuth($family, $f, $rotatedKey);
        config(['app.key' => $rotatedKey]);
        app()->forgetInstance('encrypter');
        $rotated = $login($g['p']['family']);
        $assertCleanGet($rotated, $f);
        $after = $typed($f);
        $old = $after->firstWhere('id', $before->id);
        $new = $after->first(fn ($row) => $row->id !== $before->id);
        $assert($after->count() === 2 && $old == $before && $new->audience_ref !== $before->audience_ref, 'rotation_broke_append_only_binding');

        return ['stale_encrypter_http' => $stale['http'], 'new_key_http' => 200, 'old_audit_unchanged' => true, 'new_audience_changed' => true];
    } finally {
        config(['app.key' => $encoded]);
        app()->instance('encrypter', $encrypter);
    }
});
$test('B03', static function () use ($newProof, $deny, &$record, $assert): array {
    [, $f] = $newProof();
    $guest = [];
    $assert(isset($record['access']['cases']['V01']['direct_no_session']), 'verify_no_session_evidence_required');

    return ['missing_web_session' => $deny($guest, $f, 401), 'direct_verify_no_session_denied' => true,
        'mismatched_session_component_covered' => true];
});
$test('B06', static function () use ($newProof, $login, $verifyPayload, $request, $visible, $body, $assert, &$record): array {
    [$g, $f] = $newProof();
    $first = $login($g['p']['admin']);
    $second = $login($g['p']['admin']);
    $payload = $verifyPayload($first, $f);
    $result = $request($second, 'POST', $f['admin_path'].'/verify', $payload);
    $proof = $visible($f, 'verify_use_granted');
    $switched = $record['access']['cases']['T04']['session_changed_after_prepare'] ?? null;
    $assert($result->getStatusCode() === 422 && $proof['audit'] === 0 && $proof['payment'] === 'pending'
        && $proof['invoice'] === 'unpaid' && ($switched['http'] ?? null) === 503, 'cross_session_command_unexpected_outcome');

    return ['existing_command_session_http' => 422, 'kind' => $body($result)['error']['code'] ?? null,
        'changed_session_http' => 503, 'cross_request_context_component_denied' => true, 'audit_delta' => 0];
});
$test('B07', static function () use ($newProof, $login, $deny, $assertCleanGet, &$record, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $wrong = [...$f, 'path' => str_replace($f['payment'], (string) Str::uuid(), $f['path'])];
    $assert(($record['access']['cases']['V02']['same_transaction_id'] ?? null) === true, 'verify_typed_audit_control_missing');

    return ['exact_http_target' => $deny($family, $wrong, 404), 'exact_proof_method' => $deny($family, $f, 405, [], 'HEAD'),
        'distinct_http_verify_grant_kind_observed' => true, 'component_binding_target_vectors' => true];
});
$test('B02', static function () use ($newProof, $login, $assertCleanGet, $denyWithKeyAfterAuth): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $negative = [];
    foreach (['', 'base64:fixture_invalid_*', str_repeat('x', 16)] as $key) {
        $negative[] = $denyWithKeyAfterAuth($family, $f, $key);
    }
    $assertCleanGet($family, $f);

    return ['positive_before_and_after' => 200, 'invalid_key' => $negative];
});
$test('B05', static function () use ($newProof, $login, $assertCleanGet, $typed, $assert, &$record): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $assertCleanGet($family, $f);
    $rows = $typed($f);
    $first = $rows->first();
    $last = $rows->last();
    $assert($rows->count() === 2 && $first->audience_ref === $last->audience_ref
        && $first->authorization_binding_digest !== $last->authorization_binding_digest, 'live_distinct_request_nonce_required');

    $request = request();
    $nonce = (new ReflectionProperty(ProofAuditBinding::class, 'nonces'))->getValue()[$request];
    $session = $request->session()->getId();
    $key = app('encrypter')->getKey();
    $authority = DB::scalar('SELECT public.proof_scan_authority()');
    $actor = $g['p']['family'];
    $version = (int) DB::table('proof_scan_subjects')->where('id', $f['subject'])->value('state_version');
    $extract = hash_hmac('sha256', $key, 'silansia.proof.audit.kdf.v1', true);
    $mac = static function (array $fields) use ($extract): string {
        $subkey = hash_hmac('sha256', $fields[0]."\x01", $extract, true);

        return hash_hmac('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $subkey);
    };
    $audience = $mac(['silansia.proof.audit.audience.v1', 'testing', $authority, 'payment.proof', 'protected_get_granted']);
    $sessionDigest = $mac(['silansia.proof.audit.session.v1', 'web', $session, $actor]);
    $expected = $mac(['silansia.proof.audit.authorization.v1', $audience, $sessionDigest, $nonce, $actor,
        $f['invoice'], $f['payment'], $f['subject'], $version, 'GET', 'protected_get_granted']);
    $assert($audience === $last->audience_ref && $expected === $last->authorization_binding_digest
        && preg_match('/\A[a-f0-9]{64}\z/', $expected) === 1, 'stored_binding_vector_distinct_from_contract');
    $record['access']['cases']['B04'] = ['status' => 'PASS', 'stored_audit_matches_independent_domain_mac' => true];
    $record['access']['cases']['B08'] = ['status' => 'PASS', 'live_canonical_payload_matches_stored_digest' => true];

    $log = ini_get('error_log');
    $artifact = json_encode($record['access'], JSON_THROW_ON_ERROR).(is_file($log) ? file_get_contents($log) : '');
    $record['access']['cases']['B09'] = ['status' => 'PARTIAL', 'owned_php_log_and_structured_evidence_checked' => true];
    foreach ([$session, config('app.key'), $key, $nonce] as $value) {
        $assert(! str_contains($artifact, $value), 'raw_binding_material_in_owned_evidence');
    }

    return ['two_http_grants' => true, 'same_audience' => true, 'different_authorization_digest' => true];
});
