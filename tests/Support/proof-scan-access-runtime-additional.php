<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentImageValidator;
use App\Services\Billing\StoredPaymentProof;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;

$newProof = static function (string $scenario = 'clean', bool $process = true) use ($fixtures, $submit, $scan): array {
    $g = $fixtures->graph();
    $f = $submit($g, $scenario);
    if ($process) {
        $scan($f, $scenario);
    }

    return [$g, $f];
};
$verifyPayload = static function (array &$cookies, array $f) use ($request, $body, $assert): array {
    $r = $request($cookies, 'GET', $f['admin_path'].'/review-context?operation=verify');
    $token = $body($r)['command_context'] ?? null;
    $assert($r->getStatusCode() === 200 && is_string($token), 'fixture_verify_context_required');

    return ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true];
};
$observe = static function (Closure $configure, Closure $action): mixed {
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $events = clone $original;
    $configure($events);
    $connection->setEventDispatcher($events);
    try {
        return $action();
    } finally {
        $connection->setEventDispatcher($original);
    }
};
$rescan = static function (array $f): void {
    DB::transaction(fn () => DB::scalar("SELECT public.proof_scan_rescan_or_dispose(?::uuid, ?::bigint, 'rescan', public.proof_scan_authority())",
        [$f['subject'], DB::table('proof_scan_subjects')->where('id', $f['subject'])->value('state_version')]), 1);
};
$assertCleanGet = static function (array &$cookies, array $f) use ($request, $assert): void {
    $response = $request($cookies, 'GET', $f['path']);
    $assert($response->getStatusCode() === 200 && $response->getContent() === $f['bytes'], 'expected_clean_http_grant');
};

$test('A02', static function () use ($newProof, $login, $deny, $assert): array {
    [$g, $f] = $newProof();
    $nurse = User::factory()->create(['role' => 'perawat', 'is_active' => true]);
    $wrong = $login($nurse->id);
    $family = $login($g['p']['family']);
    $wrongRole = $deny($wrong, $f, 403);
    $assert(User::whereKey($g['p']['family'])->update(['is_active' => false]) === 1, 'fixture_deactivation_failed');

    return ['wrong_role' => $wrongRole, 'inactive_session' => $deny($family, $f, 401)];
});
$test('A04', static function () use ($newProof, $login, $assertCleanGet, $fixtures, $deny): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $next = $login($g['p']['next_family']);
    $assertCleanGet($family, $f);
    DB::transaction(fn () => $fixtures->transfer($g), 1);

    return ['dual_owner_positive' => true, 'frozen_only' => $deny($family, $f, 404), 'current_only' => $deny($next, $f, 404)];
});
$test('A05', static function () use ($newProof, $login, $verifyPayload, $assertCleanGet, $request, $deny, $head, $assert): array {
    [$g, $f] = $newProof();
    $admin = $login($g['p']['admin']);
    $family = $login($g['p']['family']);
    $assertCleanGet($admin, $f);
    $assertCleanGet($family, $f);
    $payload = $verifyPayload($admin, $f);
    $verified = $request($admin, 'POST', $f['admin_path'].'/verify', $payload);
    $assert($verified->getStatusCode() === 200, 'terminal_payment_fixture_failed');
    $assertCleanGet($admin, $f);
    $assertCleanGet($family, $f);
    $head('held');
    try {
        return ['active_admin_and_family_terminal_positive' => true, 'held_admin' => $deny($admin, $f, 503), 'held_family' => $deny($family, $f, 503)];
    } finally {
        $head('active');
    }
});
$test('S01', static function () use ($legacyProof, $newProof, $login, $deny, $scan, $assert): array {
    $invoice = $legacyProof['invoice']['id'];
    $payment = $legacyProof['payment']['id'];
    $legacy = ['invoice' => $invoice, 'payment' => $payment, 'subject' => null,
        'path' => "/billing/invoices/{$invoice}/payments/{$payment}/proof"];
    $legacyFamily = $login($legacyProof['p']['family']);
    $missing = $deny($legacyFamily, $legacy, 503);
    [$g, $f] = $newProof('unknown', false);
    $family = $login($g['p']['family']);
    $queued = $deny($family, $f, 503);
    $unknown = $scan($f, 'unknown');
    $unknownDenial = $deny($family, $f, 503);
    $until = microtime(true) + 5;
    while (! DB::scalar('SELECT not_before<=clock_timestamp() FROM proof_scan_jobs WHERE subject_id=?', [$f['subject']])) {
        $assert(microtime(true) < $until, 'fixture_retry_timeout');
        usleep(50000);
    }
    $dead = $scan($f, 'unknown');
    $assert(DB::table('proof_scan_jobs')->where('subject_id', $f['subject'])->value('state') === 'DEAD_LETTER', 'fixture_dead_letter_required');

    return ['missing' => $missing, 'queued' => $queued, 'unknown' => [...$unknownDenial, ...$unknown],
        'dead_letter' => [...$deny($family, $f, 503), ...$dead]];
});
$test('S02', static function () use ($newProof, $login, $deny, $rescan, $scan, $assert): array {
    [$g, $f] = $newProof('infected');
    $family = $login($g['p']['family']);
    $admin = $login($g['p']['admin']);
    $first = $deny($family, $f, 503);
    $rescan($f);
    $next = $scan($f, 'infected');
    $assert($next['generation'] === 2 && DB::table('proof_scan_subjects')->where('id', $f['subject'])->value('security_hold') === true, 'persistent_hold_missing');

    return ['status' => 'PARTIAL', 'infected' => $first, 'rescan_same_original' => [...$deny($admin, $f, 503), ...$next],
        'persistent_hold' => true, 'clean_result_with_existing_hold_via_scanner_executed' => false];
});
$test('S05', static function () use ($newProof, $login, $assertCleanGet, $assert, $deny): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $assertCleanGet($family, $f);
    $path = Runtime::path(dirname($f['store_path']).DIRECTORY_SEPARATOR.'registry.json');
    $raw = file_get_contents($path);
    $registry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $registry['proof_size_bytes']++;
    $assert(file_put_contents($path, json_encode($registry, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'fixture_descriptor_write_failed');
    try {
        return ['registry_descriptor_mismatch' => $deny($family, $f, 503)];
    } finally {
        file_put_contents($path, $raw, LOCK_EX);
    }
});

$test('F01', static function () use ($newProof, $login, $deny, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $path = Runtime::path($f['store_path']);
    $backup = Runtime::path($path.'.fixture');
    $assert(rename($path, $backup), 'fixture_file_move_failed');
    $restored = false;
    try {
        $missing = $deny($family, $f, 503);
        $link = null;
        $linkKind = null;
        if (@symlink($backup, $path)) {
            try {
                $link = $deny($family, $f, 503);
            } finally {
                $assert(is_link($path) && unlink($path), 'fixture_link_removal_failed');
            }
            $linkKind = 'symlink';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $assert(rename($backup, $path), 'fixture_file_restore_failed');
            $restored = true;
            $directory = Runtime::path(dirname($path), true);
            $target = Runtime::path($directory.'.fixture');
            $assert(rename($directory, $target), 'fixture_directory_move_failed');
            try {
                $script = '$ErrorActionPreference="Stop"; New-Item -ItemType Junction -Path $env:SILANSIA_FIXTURE_LINK -Target $env:SILANSIA_FIXTURE_TARGET | Out-Null';
                $maker = new Process(['powershell', '-NoProfile', '-NonInteractive', '-EncodedCommand',
                    base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))], Runtime::root(),
                    ['SILANSIA_FIXTURE_LINK' => $directory, 'SILANSIA_FIXTURE_TARGET' => $target,
                        'PSModuleAnalysisCachePath' => Runtime::root().'/cache/powershell-modules']);
                $maker->setTimeout(10);
                $maker->mustRun();
                clearstatcache(true);
                $assert(realpath($directory) === realpath($target) && is_file($directory.DIRECTORY_SEPARATOR.'object.png'),
                    'fixture_junction_target_missing');
                $link = $deny($family, $f, 503);
                $linkKind = 'junction';
            } finally {
                clearstatcache(true);
                if (is_dir($directory)) {
                    $assert(realpath($directory) === realpath($target) && rmdir($directory), 'fixture_junction_removal_failed');
                }
                $assert(rename($target, $directory), 'fixture_directory_restore_failed');
            }
        }
        try {
            new StoredPaymentProof('../fixture.png', hash('sha256', $f['bytes']), strlen($f['bytes']), 'image/png');
            throw new RuntimeException('invalid_path_guard_not_reached');
        } catch (PaymentFailure $error) {
            $assert($error->kind === 'invalid_proof', 'invalid_path_guard_wrong_kind');
        }

        return ['status' => $link === null ? 'PARTIAL' : 'PASS', 'missing' => $missing,
            'traversal_metadata_rejected' => true, 'link_kind' => $linkKind, 'link_denial' => $link];
    } finally {
        if (! $restored) {
            $assert(rename($backup, $path), 'fixture_file_restore_failed');
        }
    }
});
$test('F02', static function () use ($newProof, $login, $deny, $observe, $request, $grants, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $path = Runtime::path($f['store_path']);
    $alternate = AcademicProofScanner::fixtureBytes('infected');
    try {
        file_put_contents($path, $f['bytes'].'x', LOCK_EX);
        $size = $deny($family, $f, 503);
        $assert(strlen($alternate) === strlen($f['bytes']), 'fixture_same_size_required');
        file_put_contents($path, $alternate, LOCK_EX);
        $sha = $deny($family, $f, 503);
        file_put_contents($path, $f['bytes'], LOCK_EX);
        try {
            app(PaymentImageValidator::class)->validate($f['bytes'], 'jpg');
            throw new RuntimeException('mime_guard_not_reached');
        } catch (PaymentFailure $error) {
            $assert($error->kind === 'invalid_proof', 'mime_wrong_denial');
        }
        $n = $grants($f);
        $wrote = false;
        $response = $observe(
            static function ($events) use ($path, $alternate, &$wrote): void {
                $events->listen(TransactionBeginning::class, static function () use ($path, $alternate, &$wrote): void {
                    $wrote = file_put_contents($path, $alternate, LOCK_EX) === strlen($alternate);
                });
            },
            static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); }
        );
        $assert($wrote && file_get_contents($path) === $alternate && $response->getStatusCode() === 200
            && $response->getContent() === $f['bytes'] && $grants($f) === $n + 1, 'buffer_reopened_after_capture');

        return ['size' => $size, 'sha' => $sha, 'mime_mismatch_on_validator' => true, 'same_buffer_after_replacement' => true, 'proof_bytes' => strlen($f['bytes'])];
    } finally {
        file_put_contents($path, $f['bytes'], LOCK_EX);
    }
});

$test('P01', static function () use ($newProof, $login, $deny, $accessFingerprint, $grants, &$record, $assert): array {
    [$g, $f] = $newProof('infected');
    $family = $login($g['p']['family']);
    $before = $accessFingerprint($f);
    $denial = $deny($family, $f, 503);
    $assert($before === $accessFingerprint($f) && $grants($f) === 0
        && ($record['access']['cases']['S04']['status'] ?? null) === 'PASS', 'get_denial_fingerprint_drift');

    return ['after_success_preserved' => true, 'after_denial_preserved' => true, 'denial' => $denial];
});

$test('H02', static function () use ($newProof, $login, $request, $observe, $reader, $grants, $assert): array {
    [$g, $f] = $newProof();
    $family = $login($g['p']['family']);
    $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
    $assert(is_resource($listener), 'fixture_loopback_listener_failed');
    $client = null;
    $stream = null;
    $observed = [];
    try {
        $address = stream_socket_get_name($listener, false);
        $assert(is_string($address) && preg_match('/\A127\.0\.0\.1:[0-9]+\z/', $address) === 1, 'fixture_loopback_required');
        $client = @stream_socket_client('tcp://'.$address, $errorCode, $errorText, 2);
        $stream = @stream_socket_accept($listener, 2);
        $assert(is_resource($client) && is_resource($stream), 'fixture_loopback_connection_failed');
        stream_set_timeout($stream, 2);
        $line = 'GET '.$f['path']." HTTP/1.1\r\n";
        $assert(fwrite($client, $line) === strlen($line) && fgets($stream) === $line, 'fixture_loopback_request_failed');
        $response = $observe(static function ($events) use (&$client, &$observed, $reader, $f, $assert): void {
            $events->listen(TransactionCommitted::class, static function () use (&$client, &$observed, $reader, $f, $assert): void {
                $observed = $reader(static function ($observer, int $primary, int $secondary) use ($f): array {
                    return ['primary_pid' => $primary, 'observer_pid' => $secondary, 'committed_at_disconnect' => $observer->table('proof_scan_audit_events')
                        ->where('subject_id', $f['subject'])->where('event_type', 'protected_get_granted')->count()];
                });
                $assert($observed['committed_at_disconnect'] === 1, 'fixture_disconnect_before_commit');
                stream_socket_shutdown($client, STREAM_SHUT_RDWR);
                fclose($client);
                $client = null;
            });
        }, static function () use ($request, &$family, $f) { return $request($family, 'GET', $f['path']); });
        $assert($client === null && fread($stream, 1) === '' && feof($stream) && $response->getStatusCode() === 200
            && $response->getContent() === $f['bytes'] && $grants($f) === 1, 'fixture_disconnect_audit_not_proven');

        return [...$observed, 'connection_eof' => true, 'proof_bytes_sent_after_disconnect' => 0, 'committed_grants' => 1,
            'transport_scope' => 'loopback fixture using Laravel HTTP kernel'];
    } finally {
        foreach ([$client, $stream, $listener] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
});

require __DIR__.'/proof-scan-access-runtime-faults.php';
$test('FIN_HTTP_REJECT_RESUBMIT', static function () use ($newProof, $login, $request, $body, $submit, $scan, $verifyPayload, $assert): array {
    [$g, $first] = $newProof();
    $admin = $login($g['p']['admin']);
    $context = $request($admin, 'GET', $first['admin_path'].'/review-context?operation=reject');
    $rejectToken = $body($context)['command_context'] ?? null;
    $assert($context->getStatusCode() === 200 && is_string($rejectToken), 'legacy_http_reject_context_failed');
    $reject = $request($admin, 'POST', $first['admin_path'].'/reject', ['command_context' => $rejectToken, 'confirm' => true,
        'reason_code' => 'receipt_not_confirmed', 'public_reason' => 'Bukti sintetis diganti.']);
    $assert($reject->getStatusCode() === 200, 'legacy_http_reject_failed');
    $old = DB::table('payments')->where('id', $first['payment'])->sole();
    $assert($old->status === 'rejected', 'legacy_first_attempt_status');
    $second = $submit($g, 'clean');
    $scan($second, 'clean');
    $payload = $verifyPayload($admin, $second);
    $verify = $request($admin, 'POST', $second['admin_path'].'/verify', $payload);
    $assert($verify->getStatusCode() === 200, 'legacy_http_replacement_verify_failed');
    $new = DB::table('payments')->where('id', $second['payment'])->sole();
    $invoice = DB::table('invoices')->where('id', $first['invoice'])->sole();
    $count = DB::table('proof_scan_audit_events')->where('subject_id', $second['subject'])->where('event_type', 'verify_use_granted')->count();
    $assert($new->status === 'verified' && $invoice->status === 'paid' && $new->verified_at === $invoice->paid_at
        && $invoice->bill_to_family_id === $g['p']['family'] && $count === 1
        && DB::table('payments')->where('id', $first['payment'])->sole() == $old, 'legacy_replacement_regression');

    return ['reject_http' => 200, 'verify_http' => 200, 'first_preserved' => true, 'replacement_verified' => true,
        'invoice_paid' => true, 'frozen_owner_preserved' => true, 'audit_delta' => 1];
});
require __DIR__.'/proof-scan-access-runtime-binding.php';
require __DIR__.'/proof-scan-access-runtime-concurrency.php';
