<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\User;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\ProofScanSchemaContract;
use App\Support\Billing\ProofAuditBinding;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;

$assert = static function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
$checks = [];
$case = static function (string $id, Closure $test) use (&$checks): void {
    try {
        $checks[$id] = ['status' => 'PASS', ...($test() ?? [])];
    } catch (Throwable $error) {
        $checks[$id] = ['status' => 'FAIL', 'exception' => get_class($error),
            'reason' => preg_match('/\A[a-z0-9_-]{1,100}\z/', $error->getMessage()) ? $error->getMessage() : 'component_exception',
            'line' => $error->getLine()];
    }
};
$deny = static function (Closure $operation) use ($assert): void {
    try {
        $operation();
    } catch (PaymentFailure $error) {
        $assert($error->kind === 'proof_use_unavailable', 'binding_wrong_denial');

        return;
    }
    throw new RuntimeException('binding_expected_denial');
};
try {
    Runtime::environment();
    Runtime::assertProtected();
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(Runtime::root().'/environment');
    $app->loadEnvironmentFrom('absent.env');
    Runtime::attach($app);
    $app->make(Kernel::class)->bootstrap();
    Runtime::resolved($app);
    $assert($app->environment('testing') && ! $app->configurationIsCached() && getenv('APP_ENV') === 'testing'
        && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:'
        && $app['db']->getConnections() === [], 'binding_no_database_allowed');
    $originalKey = config('app.key');
    $originalEncrypter = app('encrypter');
    $assert(getenv('APP_KEY') === $originalKey && empty(config('app.previous_keys')), 'binding_synthetic_key_required');
    $session = app('session')->driver();
    $session->start();
    $actor = new User;
    $actor->id = (string) Str::uuid();
    $actor->role = 'admin';
    $actor->is_active = true;
    $guard = Auth::guard('web');
    $guard->setUser($actor);
    $session->put($guard->getName(), $actor->id);
    [$invoice, $payment, $subject, $wrongTarget] = array_map(static fn () => (string) Str::uuid(), range(1, 4));
    $authority = 'academic-owned:'.str_repeat('a', 64);
    $kind = 'protected_get_granted';
    $target = [$authority, $actor->id, $invoice, $payment, $subject, 3];
    $makeRequest = static function (string $method = 'GET', bool $attach = true, string $domain = '127.0.0.1') use ($app, $session, $actor, $invoice, $payment): Request {
        $path = $method === 'POST' ? "/admin/billing/invoices/{$invoice}/payments/{$payment}/verify" : "/billing/invoices/{$invoice}/payments/{$payment}/proof";
        $request = Request::create('http://'.$domain.$path, $method, [], [], [], ['HTTP_ORIGIN' => 'https://fixture.invalid',
            'HTTP_REFERER' => 'https://fixture.invalid/']);
        if ($attach) {
            $request->setLaravelSession($session);
        }
        $request->setUserResolver(static fn () => $actor);
        $route = clone $app['router']->getRoutes()->getByName($method === 'POST' ? 'payment.admin.verify' : 'payment.proof');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $app->instance('request', $request);

        return $request;
    };
    $binder = app(ProofAuditBinding::class);
    $request = $makeRequest();
    $prepared = $binder->prepare($kind, ...$target);
    $digests = $prepared(...$target);
    $assert(count($digests) === 2 && array_reduce($digests, static fn ($ok, $value) => $ok && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1, true),
        'binding_positive_shape_invalid');

    $case('B01', static function () use ($app, $binder, $originalKey, $originalEncrypter, $prepared, $digests, $target, $kind, $deny, $assert): array {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        try {
            $deny(fn () => $prepared(...$target));
            $app->forgetInstance('encrypter');
            $deny(fn () => $prepared(...$target));
            $assert($binder->prepare($kind, ...$target)(...$target) !== $digests, 'rotated_key_must_change_binding');
        } finally {
            config(['app.key' => $originalKey]);
            $app->instance('encrypter', $originalEncrypter);
        }

        return ['old_preparation_denied' => true, 'new_key_binding_distinct' => true, 'historical_database_audit_executed' => false];
    });
    $case('B02', static function () use ($binder, $originalKey, $kind, $target, $deny): array {
        try {
            foreach (['', 'base64:invalid!*', 'base64:'.base64_encode(str_repeat('x', 16)), str_repeat('a', 31)] as $invalid) {
                config(['app.key' => $invalid]);
                $deny(fn () => $binder->prepare($kind, ...$target));
            }
        } finally {
            config(['app.key' => $originalKey]);
        }

        return ['invalid_key_variants_denied' => 4];
    });
    $case('B03', static function () use ($app, $binder, $makeRequest, $request, $session, $guard, $actor, $kind, $target, $deny): array {
        try {
            $makeRequest(attach: false);
            $deny(fn () => $binder->prepare($kind, ...$target));
            $app->instance('request', $request);
            $session->forget($guard->getName());
            $deny(fn () => $binder->prepare($kind, ...$target));
            $session->put($guard->getName(), (string) Str::uuid());
            $deny(fn () => $binder->prepare($kind, ...$target));
        } finally {
            $session->put($guard->getName(), $actor->id);
            $app->instance('request', $request);
        }

        return ['missing_or_invalid_session_variants_denied' => 3, 'writer_executed' => false];
    });

    $nonces = (new ReflectionProperty(ProofAuditBinding::class, 'nonces'))->getValue();
    $nonce = $nonces[$request];
    $case('B04', static function () use ($request, $session, $target, $originalEncrypter, $digests, $nonce, $kind, $assert): array {
        [$authority, $actor, $invoice, $payment, $subject, $version] = $target;
        $domains = ['silansia.proof.audit.audience.v1', 'silansia.proof.audit.session.v1', 'silansia.proof.audit.authorization.v1'];
        $extract = hash_hmac('sha256', $originalEncrypter->getKey(), 'silansia.proof.audit.kdf.v1', true);
        $keys = array_map(static fn (string $domain) => hash_hmac('sha256', $domain."\x01", $extract, true), $domains);
        $mac = static fn (array $fields, int $index) => hash_hmac('sha256',
            json_encode([$domains[$index], ...$fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $keys[$index]);
        $audience = $mac(['testing', $authority, $request->route()->getName(), $kind], 0);
        $sessionDigest = $mac(['web', $session->getId(), $actor], 1);
        $auth = $mac([$audience, $sessionDigest, $nonce, $actor, $invoice, $payment, $subject, $version, 'GET', $kind], 2);
        $assert(count(array_unique($keys)) === 3 && [$audience, $auth] === $digests, 'binding_kdf_or_mac_vector_mismatch');

        return ['independent_hkdf_vector_matched' => true, 'distinct_subkeys' => 3, 'digest_length' => 64];
    });
    $case('B05', static function () use ($app, $request, $makeRequest, $binder, $kind, $target, $digests, $assert): array {
        $same = $binder->prepare($kind, ...$target)(...$target);
        $makeRequest(domain: 'fixture.invalid');
        try {
            $new = $binder->prepare($kind, ...$target)(...$target);
            $assert($same === $digests && $new[0] === $digests[0] && $new[1] !== $digests[1], 'binding_nonce_must_change_per_request');
        } finally {
            $app->instance('request', $request);
        }

        return ['same_request_nonce_stable' => true, 'different_request_digest_distinct' => true, 'client_host_not_authority' => true];
    });
    $case('B06', static function () use ($app, $request, $makeRequest, $session, $prepared, $target, $deny): array {
        $sessionId = $session->getId();
        try {
            $makeRequest();
            $deny(fn () => $prepared(...$target));
            $app->instance('request', $request);
            $session->setId(Str::random(40));
            $deny(fn () => $prepared(...$target));
        } finally {
            $session->setId($sessionId);
            $app->instance('request', $request);
        }

        return ['foreign_request_denied' => true, 'changed_session_denied' => true, 'database_audit_executed' => false];
    });
    $case('B07', static function () use ($app, $request, $makeRequest, $binder, $kind, $prepared, $target, $wrongTarget, $deny): array {
        foreach ([2 => $wrongTarget, 3 => $wrongTarget, 4 => $wrongTarget, 5 => 4] as $index => $value) {
            $different = $target;
            $different[$index] = $value;
            $deny(fn () => $prepared(...$different));
        }
        $deny(fn () => $binder->prepare('verify_use_granted', ...$target));
        $makeRequest('POST');
        try {
            $deny(fn () => $binder->prepare($kind, ...$target));
            $binder->prepare('verify_use_granted', ...$target)(...$target);
        } finally {
            $app->instance('request', $request);
        }

        return ['mismatches_denied' => 6, 'post_exact_context_accepted' => true];
    });
    $case('B08', static function () use ($prepared, $target, $digests, $deny, $assert): array {
        $uuid = $target;
        $uuid[4] = strtoupper($target[4]);
        $deny(fn () => $prepared(...$uuid));
        $version = $target;
        $version[5] = 0;
        $deny(fn () => $prepared(...$version));
        $assert($prepared(...$target) === $digests, 'canonical_digest_changed');

        return ['canonical_hex_equal' => true, 'noncanonical_uuid_and_nonpositive_version_denied' => true];
    });
    $pins = (new ReflectionClassConstant(ProofScanSchemaContract::class, 'SOURCES'))->getValue();
    $case('PIN01-A', static function () use ($assert, $pins): array {
        foreach ($pins as $file => $expected) {
            $actual = hash('sha256', str_replace("\r\n", "\n", file_get_contents(base_path($file))));
            $assert(hash_equals($expected, $actual), 'pinned_source_not_matched');
        }
        (new ProofScanSchemaContract)->prepareExpected();

        return ['contract_prepared' => true, 'matched_pins' => count($pins), 'database_connected' => false];
    });
    $case('PIN01-B', static function () use ($app, $assert, $pins): array {
        $root = Runtime::path(Runtime::root().DIRECTORY_SEPARATOR.'pin-fixture');
        $assert(mkdir($root, 0700), 'pin_fixture_root_failed');
        $base = $app->basePath();
        foreach (array_keys($pins) as $file) {
            $copy = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_dir(dirname($copy))) {
                $assert(mkdir(dirname($copy), 0700, true), 'pin_fixture_directory_failed');
            }
            Runtime::path($copy);
            $assert(copy($app->basePath($file), $copy), 'pin_fixture_copy_failed');
        }
        $altered = 'app/Services/Billing/ProofScanReadiness.php';
        $copy = Runtime::path($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $altered));
        $assert(file_put_contents($copy, "\n// Fixture PIN01-B.\n", FILE_APPEND | LOCK_EX) !== false, 'pin_fixture_write_failed');
        try {
            $app->setBasePath($root);
            try {
                (new ProofScanSchemaContract)->prepareExpected();
            } catch (PaymentFailure $error) {
                $assert($error->kind === 'proof_scan_source_mismatch', 'unexpected_schema_pin_error');

                return ['kind' => $error->kind, 'fixture_only' => true, 'database_connected' => false];
            }
            throw new RuntimeException('expected_proof_scan_source_mismatch');
        } finally {
            $app->setBasePath($base);
            Runtime::assertProtected();
        }
    });
    $case('B09', static function () use ($session, $originalEncrypter, $originalKey, $nonce, $assert, &$checks): array {
        $log = ini_get('error_log');
        $evidence = json_encode($checks, JSON_THROW_ON_ERROR).(is_file($log) ? file_get_contents($log) : '');
        foreach ([$session->getId(), $originalKey, $originalEncrypter->getKey(), $nonce] as $secret) {
            $assert(! str_contains($evidence, $secret), 'binding_component_secret_detected');
        }

        return ['synthetic_evidence_and_owned_php_log_checked' => true, 'full_http_log_checked' => false];
    });
    foreach ($app['db']->getConnections() as $connection) {
        $assert(! $connection->getRawPdo() instanceof PDO, 'binding_database_connection_opened');
    }
    Runtime::assertProtected();
    $ok = array_filter($checks, static fn (array $entry) => $entry['status'] !== 'PASS') === [];
    echo json_encode(['ok' => $ok, 'database_connected' => false, 'runtime_scope' => 'binding_component_only', 'checks' => $checks],
        JSON_THROW_ON_ERROR).PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'exception' => get_class($error), 'line' => $error->getLine(),
        'reason' => preg_match('/\A[a-z0-9_-]{1,100}\z/', $error->getMessage()) ? $error->getMessage() : 'binding_harness_stopped']).PHP_EOL;
    exit(1);
}
