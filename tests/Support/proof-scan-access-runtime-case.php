<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\PaymentService;
use App\Support\Billing\ProofAuditBinding;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;

$owned();
$assert(getenv('SILANSIA_ACADEMIC_PROOF_SCAN') === '1', 'access_academic_flag_required');
config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only-approval',
    'invoice_issuance.enabled' => true, 'payment_core.enabled' => true, 'payment_http.enabled' => true,
    'payment_http.synthetic_validation' => true, 'session.driver' => 'file']);
$proofRoot = app(PaymentProofEnvironment::class)->root();
Runtime::path($proofRoot);
$fixtures = new class
{
    use BillingIntegrityFixtures {
        billingGraph as public graph;
        billingLocks as public locks;
        billingTransfer as public transfer;
    }
};
$record['access'] = ['cases' => []];
$test = static function (string $id, Closure $test) use (&$record, $owned): bool {
    $owned();
    try {
        $details = $test();
        $record['access']['cases'][$id] = ['status' => 'PASS', ...($details ?? [])];

        return true;
    } catch (Throwable $error) {
        $kind = $error->getMessage();
        $record['access']['cases'][$id] = ['status' => 'FAIL',
            'reason' => preg_match('/\A[a-z0-9_-]{1,100}\z/', $kind) ? $kind : 'case_exception',
            'exception' => get_class($error), 'line' => $error->getLine(),
            'sqlstate' => $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null];

        return false;
    }
};

$profile = [
    'version' => 'academic-sim-v1', 'definition_version' => 1,
    'stage_plan' => [['stage_id' => 'synthetic_stage_v1', 'is_simulation' => true]],
    'allowed_versions' => ['adapter_version' => '1', 'scanner_identity' => 'synthetic_mock',
        'engine_id' => 'synthetic_mock', 'engine_version' => '1', 'signature_set_id' => 'not_applicable_simulation',
        'signature_version' => 'not_applicable_simulation', 'auth_scheme_version' => 'trusted_in_process_v1',
        'key_epoch_reference' => 'not_applicable_owned_runtime'],
    'retry_profile' => ['max_attempts' => 2, 'adapter_timeout_seconds' => 5, 'lease_seconds' => 30, 'retry_due_seconds' => 1],
    'freshness_profile' => ['verdict_seconds' => 120], 'resource_profile' => ['worker_concurrency' => 1, 'dispatcher' => 'manual_one_shot'],
];
$policy = DB::transaction(fn () => DB::scalar('SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())',
    [json_encode($profile, JSON_THROW_ON_ERROR)]), 1);
$epoch = 0;
$incarnation = (string) Str::uuid();
$head = static function (string $admission) use ($policy, $incarnation, &$epoch, $owned): void {
    $owned();
    $epoch = DB::transaction(fn () => DB::scalar('SELECT public.proof_scan_set_head(?,?,?,?,public.proof_scan_authority())',
        [$policy, $incarnation, $epoch, $admission]), 1);
};
$head('active');

$http = $app->make(HttpKernel::class);
$httpEvidenceProbe = [];
$secrets = [];
$rememberSecrets = static function () use (&$secrets): void {
    $key = config('app.key');
    $request = request();
    $nonce = (new ReflectionProperty(ProofAuditBinding::class, 'nonces'))->getValue();
    $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '{}', true);
    foreach ([$key, is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : null,
        $request->hasSession() ? $request->session()->getId() : null, $nonce[$request] ?? null,
        $identity['token'] ?? null, getenv('SILANSIA_RUNTIME_OWNER')] as $value) {
        if (is_string($value) && strlen($value) >= 32) {
            $secrets[$value] = true;
        }
    }
};
$request = static function (array &$cookies, string $method, string $path, array $data = [], array $headers = []) use ($http, $rememberSecrets, &$httpEvidenceProbe) {
    // The CLI reuses the Store object. save() clears isStarted(), not its attributes.
    app('session')->driver()->flush();
    Auth::forgetGuards();
    $json = $method === 'POST' && $path !== '/login';
    $server = ['HTTP_ACCEPT' => 'application/json', ...$headers];
    if ($method === 'POST') {
        $server['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN'] ?? '';
    }
    if ($json) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create('http://127.0.0.1'.$path, $method, $json ? [] : $data, $cookies, [], $server,
        $json ? json_encode($data, JSON_THROW_ON_ERROR) : null);
    $response = $http->handle($request);
    $http->terminate($request, $response);
    $rememberSecrets();
    $httpEvidenceProbe[] = (string) $response->getContent().json_encode($response->headers->all(), JSON_THROW_ON_ERROR);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }

    return $response;
};
$login = static function (string $id) use ($request, $assert): array {
    static $client = 1;
    $loopback = ['REMOTE_ADDR' => '127.0.1.'.(++$client)];
    $email = User::findOrFail($id)->email;
    $cookies = [];
    $request($cookies, 'GET', '/', [], $loopback);
    $assert(isset($cookies['XSRF-TOKEN']), 'web_csrf_cookie_missing');
    $response = $request($cookies, 'POST', '/login', ['email' => $email, 'password' => 'password'], $loopback);
    $assert($response->getStatusCode() === 302 && isset($cookies[config('session.cookie')])
        && Auth::guard('web')->id() === $id, 'web_login_failed');

    return $cookies;
};

$submit = static function (array $g, string $scenario) use ($owned, $assert): array {
    $owned();
    $invoice = $g['invoice']['id'];
    $owner = $g['p']['family'];
    $ref = (string) Str::uuid();
    $bytes = AcademicProofScanner::fixtureBytes($scenario);
    $upload = Runtime::path(Runtime::root().'/uploads/'.$ref.'.png');
    $assert(file_put_contents($upload, $bytes) === strlen($bytes), 'access_upload_fixture_failed');
    app(LocalPaymentProofStore::class)->intake(new UploadedFile($upload, 'fixture.png', 'image/png', null, true), $ref, $owner, $invoice);
    Auth::forgetGuards();
    Auth::guard('web')->setUser(User::findOrFail($owner));
    try {
        $result = app(PaymentService::class)->submit(new PaymentCommand([
            'operation' => 'submit', 'actor_id' => $owner, 'invoice_id' => $invoice,
            'revision_id' => $g['revision']['id'], 'request_id' => (string) Str::uuid(), 'proof_reference' => $ref, 'confirm' => true,
        ]));
    } finally {
        Auth::forgetGuards();
        unlink($upload);
    }
    $q = DB::table('proof_scan_subjects')->where('payment_id', $result->paymentId)->first();
    $assert($q !== null && $q->state === 'QUEUED', 'access_writer_graph_required');

    return ['payment' => $result->paymentId, 'subject' => $q->id, 'invoice' => $invoice, 'bytes' => $bytes,
        'path' => '/billing/invoices/'.$invoice.'/payments/'.$result->paymentId.'/proof',
        'admin_path' => '/admin/billing/invoices/'.$invoice.'/payments/'.$result->paymentId,
        'store_path' => app(PaymentProofEnvironment::class)->root().DIRECTORY_SEPARATOR.$ref.DIRECTORY_SEPARATOR.'object.png'];
};
$scan = static function (array $f, string $verdict) use ($kernel, $owned, $assert): array {
    $owned();
    $output = new BufferedOutput;
    $exit = $kernel->call('silansia:academic-proof-scan', [], $output);
    $lines = preg_split('/\R/', trim($output->fetch()));
    $assert(count($lines) === 2 && $lines[0] === AcademicProofScanner::LABEL, 'access_scanner_label_required');
    $result = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
    $q = DB::table('proof_scan_subjects')->where('id', $f['subject'])->first();
    $expectedState = ['clean' => 'CLEAN_ELIGIBLE', 'unknown' => 'UNKNOWN_HELD', 'infected' => 'INFECTED_HELD'][$verdict];
    $assert($exit === 0 && $result['is_simulation'] === true && $result['status'] === 'accepted'
        && $result['verdict'] === $verdict && $q->state === $expectedState, 'access_scanner_case_failed');

    return ['state' => $q->state, 'version' => (int) $q->state_version, 'generation' => (int) $q->generation, 'simulation' => true];
};
$reader = static function (Closure $callback, ?int $knownPrimaryPid = null) use ($guard, $assert) {
    $primary = DB::connection();
    $config = $primary->getConfig();
    $pdo = (new PostgresConnector)->connect($config);
    $observer = new PostgresConnection($pdo, $config['database'], '', $config);
    try {
        $guard->assertTarget($observer);
        $identity = $observer->selectOne("SELECT pg_backend_pid() AS pid, session_user::text AS role, current_setting('server_version_num')::int AS version");
        $pid = $knownPrimaryPid ?? $primary->scalar('SELECT pg_backend_pid()');
        $assert($pid !== $identity->pid && $identity->role === $guard->identity['owner']
            && $identity->version >= 180000 && $identity->version < 190000, 'access_distinct_backend_required');

        return $callback($observer, $pid, $identity->pid);
    } finally {
        $observer->disconnect();
    }
};
$grants = static fn (array $f, string $kind = 'protected_get_granted') => DB::table('proof_scan_audit_events')
    ->where('subject_id', $f['subject'])->where('event_type', $kind)->count();
$facts = static fn (array $f) => [
    DB::table('invoices')->where('id', $f['invoice'])->first(),
    DB::table('payments')->where('id', $f['payment'])->first(),
    DB::table('billing_actions')->where('invoice_id', $f['invoice'])->count(),
    DB::table('billing_requests')->where('invoice_id', $f['invoice'])->count(),
];
$accessFingerprint = static function (array $f) use ($facts): string {
    $technical = [];
    foreach (['subjects', 'jobs', 'verdicts'] as $suffix) {
        $key = $suffix === 'subjects' ? 'id' : 'subject_id';
        $technical[$suffix] = DB::table('proof_scan_'.$suffix)->where($key, $f['subject'])->orderBy('id')->get()->all();
    }
    $technical['attempts'] = DB::table('proof_scan_attempts')->whereIn('job_id',
        DB::table('proof_scan_jobs')->where('subject_id', $f['subject'])->select('id'))->orderBy('id')->get()->all();
    $technical['head'] = DB::table('proof_scan_policy_heads')->orderBy('id')->get()->all();

    return hash('sha256', json_encode([$facts($f), $technical, hash_file('sha256', Runtime::path($f['store_path']))], JSON_THROW_ON_ERROR));
};
$body = static fn ($response): array => json_decode((string) $response->getContent(), true) ?? [];
$deny = static function (array &$cookies, array $f, int $expected, array $headers = [], string $method = 'GET') use ($request, $grants, $facts, $assert, $body, &$record): array {
    $before = $facts($f);
    $count = $grants($f);
    $response = $request($cookies, $method, $f['path'], [], $headers);
    $status = $response->getStatusCode();
    $safe = ! $response->headers->has('Content-Disposition') && ! str_starts_with((string) $response->headers->get('Content-Type'), 'image/');
    $attempt = ['http' => $status, 'expected_http' => $expected, 'proof_bytes' => $safe ? 0 : strlen((string) $response->getContent()),
        'audit_delta' => $grants($f) - $count, 'finance_unchanged' => $facts($f) == $before, 'kind' => $body($response)['error']['code'] ?? null];
    $record['access']['denial_observations'][] = $attempt;
    $assert($status === $expected && $safe && $attempt['audit_delta'] === 0 && $attempt['finance_unchanged'], 'unexpected_protected_get_denial');

    return ['http' => $status, 'proof_bytes' => 0, 'audit_delta' => 0, 'finance_unchanged' => true, 'kind' => $body($response)['error']['code'] ?? null];
};

$graph = $fixtures->graph();
$f = $submit($graph, 'clean');
$record['access']['clean'] = $scan($f, 'clean');
$family = $login($graph['p']['family']);
$admin = $login($graph['p']['admin']);
$other = $login($graph['p']['next_family']);
$good = $test('S04', static function () use (&$family, $f, $request, $reader, $accessFingerprint, $grants, $body, $assert, &$record): array {
    $before = $accessFingerprint($f);
    $connection = DB::connection();
    $events = $connection->getEventDispatcher();
    $probe = clone $events;
    $catalog = ['off_lock' => ['queries' => 0, 'sql_ms' => 0.0], 'locked' => ['queries' => 0, 'sql_ms' => 0.0]];
    $ordered = [];
    $probe->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$catalog, &$ordered): void {
        $sql = $event->sql;
        $stage = $event->connection->transactionLevel() === 0 ? 'off_lock' : 'locked';
        if (preg_match('/\b(?:pg_class|pg_proc|pg_constraint|pg_attribute|pg_index|pg_trigger)\b/i', $sql)) {
            $catalog[$stage]['queries']++;
            $catalog[$stage]['sql_ms'] += $event->time;
            $ordered[] = $stage.':catalog';
        } elseif (str_contains($sql, 'proof_scan_authority()') && ! str_contains($sql, 'proof_scan_append_audit(')) {
            $ordered[] = $stage.':authority';
        }
    });
    $connection->setEventDispatcher($probe);
    try {
        $response = $request($family, 'GET', $f['path']);
    } finally {
        $connection->setEventDispatcher($events);
    }
    $catalogIndex = array_search('off_lock:catalog', $ordered, true);
    $authorityIndex = array_search('off_lock:authority', $ordered, true);
    $record['access']['performance'] = [...$catalog,
        'off_lock_catalog_before_authority' => $catalogIndex !== false && $authorityIndex !== false && $catalogIndex < $authorityIndex,
        'buffer_order_evidence' => 'code order, authority probe and later file-mutation tests'];
    $record['access']['positive_http'] = ['status' => $response->getStatusCode(), 'content_type' => $response->headers->get('Content-Type'),
        'kind' => $body($response)['error']['code'] ?? null, 'audit' => $grants($f)];
    $assert($response->getStatusCode() === 200 && $response->getContent() === $f['bytes'], 'clean_get_control_failed');
    ob_start();
    try {
        $visibility = $reader(static function ($observer, int $primaryPid, int $observerPid) use ($f, $assert): array {
            $rows = $observer->table('proof_scan_audit_events')->where('subject_id', $f['subject'])->where('event_type', 'protected_get_granted')->count();
            $transaction = DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction();
            $assert($rows === 1 && ob_get_length() === 0 && ! $transaction, 'commit_before_first_byte_required');

            return ['primary_pid' => $primaryPid, 'observer_pid' => $observerPid, 'committed_observed' => $rows,
                'proof_bytes_before_send' => 0, 'transaction_open' => $transaction];
        });
        $response->sendContent();
        $sent = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $assert($sent === $f['bytes'] && $accessFingerprint($f) === $before, 'protected_get_buffer_or_finance_drift');
    $record['access']['cases']['T01'] = ['status' => 'PASS', ...$visibility, 'proof_bytes_after_send' => strlen($sent), 'same_buffer' => true];
    $record['access']['cases']['P01'] = ['status' => 'PARTIAL', 'financial_technical_and_file_preserved_after_success' => true];

    return ['http' => 200, 'proof_bytes' => strlen($sent), 'audit_delta' => 1, 'state' => 'CLEAN_ELIGIBLE'];
});
$test('A01', static function () use ($f, $deny) {
    $guest = [];

    return $deny($guest, $f, 401);
});
if (! $good) {
    $record['access']['remaining_blocked'] = 'clean_positive_control_failed';
    throw new RuntimeException('clean_positive_control_failed');
}
$test('A03', static function () use (&$other, &$family, $f, $fixtures, $deny) {
    $different = $fixtures->graph();
    $mixed = [...$f, 'path' => str_replace($f['invoice'], $different['invoice']['id'], $f['path'])];

    return ['cross_family' => $deny($other, $f, 404), 'mismatched_parent' => $deny($family, $mixed, 404)];
});
$test('H01', static function () use (&$family, $f, $deny, $request, $assert, $grants, &$record) {
    $variants = ['head' => $deny($family, $f, 405, [], 'HEAD')];
    foreach (['HTTP_RANGE' => 'bytes=0-1', 'HTTP_IF_NONE_MATCH' => '*', 'HTTP_IF_MODIFIED_SINCE' => 'Thu, 19 Nov 2026 00:00:00 GMT'] as $header => $value) {
        $variants[$header] = $deny($family, $f, 422, [$header => $value]);
    }
    $response = $request($family, 'GET', $f['path']);
    $headers = $response->headers;
    $assert($response->getContent() === $f['bytes'] && $headers->hasCacheControlDirective('private')
        && $headers->hasCacheControlDirective('no-store') && $headers->get('X-Content-Type-Options') === 'nosniff'
        && $headers->get('Referrer-Policy') === 'no-referrer' && ! $headers->has('ETag') && ! $headers->has('Last-Modified')
        && $headers->get('Content-Disposition') === 'attachment; filename="bukti.png"', 'protected_get_headers_invalid');

    $before = $grants($f);
    $ref = basename(dirname($f['store_path']));
    $alternate = $request($family, 'GET', '/storage/payment-proofs/'.$ref.'/object.png');
    $record['access']['alternate_route'] = ['http' => $alternate->getStatusCode(), 'content_type' => $alternate->headers->get('Content-Type'),
        'attachment' => $alternate->headers->has('Content-Disposition'), 'audit_delta' => $grants($f) - $before];
    $assert(in_array($alternate->getStatusCode(), [403, 404], true) && ! $alternate->headers->has('Content-Disposition')
        && ! str_starts_with((string) $alternate->headers->get('Content-Type'), 'image/') && $grants($f) === $before, 'public_proof_route_unexpected');

    return [...$variants, 'normal_headers_verified' => true, 'alternate_route_http' => $alternate->getStatusCode(), 'alternate_proof_bytes' => 0,
        'alternate_scope' => 'Laravel route'];
});

$test('V01', static function () use (&$admin, $f, $request, $body, $head, $assert, $reader, $grants, &$record) {
    $context = $request($admin, 'GET', $f['admin_path'].'/review-context?operation=verify');
    $token = $body($context)['command_context'] ?? null;
    $record['access']['verify_context_http'] = ['status' => $context->getStatusCode(), 'kind' => $body($context)['error']['code'] ?? null];
    $assert($context->getStatusCode() === 200 && is_string($token), 'verify_web_context_failed');
    $payload = ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true];
    $response = $request($admin, 'POST', $f['admin_path'].'/verify', $payload);
    $record['access']['positive_verify_http'] = ['status' => $response->getStatusCode(), 'kind' => $body($response)['error']['code'] ?? null];
    $assert($response->getStatusCode() === 200 && ($body($response)['replayed'] ?? null) === false, 'fresh_verify_control_failed');
    $visible = $reader(static function ($observer, int $primaryPid, int $observerPid) use ($f, $assert): array {
        $result = $observer->selectOne("SELECT i.status AS invoice, p.status AS payment, a.xmin::text AS audit_xid,
            p.xmin::text AS payment_xid, i.xmin::text AS invoice_xid, q.xmin::text AS request_xid, b.xmin::text AS action_xid
            FROM public.payments p JOIN public.invoices i ON i.id=p.invoice_id
            JOIN public.proof_scan_subjects s ON s.payment_id=p.id
            JOIN public.proof_scan_audit_events a ON a.subject_id=s.id AND a.event_type='verify_use_granted'
            JOIN public.billing_requests q ON q.outcome_payment_id=p.id AND q.operation='verify'
            JOIN public.billing_actions b ON b.billing_request_id=q.id AND b.action='payment_verified' WHERE p.id=?", [$f['payment']]);
        $assert($result !== null && $result->payment === 'verified' && $result->invoice === 'paid'
            && count(array_unique([$result->audit_xid, $result->payment_xid, $result->invoice_xid, $result->request_xid, $result->action_xid])) === 1,
            'verify_same_commit_required');

        return ['committed_visible' => true, 'same_transaction_id' => true, 'primary_pid' => $primaryPid, 'observer_pid' => $observerPid];
    });
    $record['access']['cases']['V02'] = ['status' => 'PARTIAL', ...$visible, 'faults_executed' => 0];
    $head('held');
    try {
        $replay = $request($admin, 'POST', $f['admin_path'].'/verify', $payload);
        $assert($replay->getStatusCode() === 200 && ($body($replay)['replayed'] ?? null) === true
            && $grants($f, 'verify_use_granted') === 1, 'verify_canonical_replay_changed');
    } finally {
        $head('active');
    }

    return ['status' => 'PARTIAL', 'http' => 200, 'proof_bytes' => 0, 'audit_delta' => 1, 'held_replay' => true,
        'negative_fresh_cases_pending' => true];
});
require __DIR__.'/proof-scan-access-runtime-additional.php';
if ($proofClosure) {
    require __DIR__.'/proof-scan-access-runtime-closure.php';
}

$test('B09', static function () use (&$httpEvidenceProbe, &$secrets, &$record, $assert): array {
    $log = ini_get('error_log');
    $sinks = [...$httpEvidenceProbe,
        json_encode($record['access'], JSON_THROW_ON_ERROR),
        json_encode(DB::table('proof_scan_audit_events')->get()->all(), JSON_THROW_ON_ERROR),
        is_file($log) ? file_get_contents($log) : ''];
    foreach ($sinks as $sink) {
        foreach (array_keys($secrets) as $secret) {
            $assert(! str_contains($sink, $secret), 'raw_secret_found_in_retest_sink');
        }
    }
    $assert(count($httpEvidenceProbe) > 1 && count($secrets) > 1, 'retest_leak_probe_coverage_missing');

    return ['http_responses_checked' => count($httpEvidenceProbe), 'raw_fixture_variants_checked' => count($secrets),
        'audit_and_owned_php_log_checked' => true, 'structured_evidence_checked' => true, 'raw_matches' => 0];
});
unset($httpEvidenceProbe, $secrets);
