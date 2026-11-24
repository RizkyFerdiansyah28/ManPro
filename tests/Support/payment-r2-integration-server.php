<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\BillingC3Acceptance;
use Tests\Support\BillingC3Bootstrap;
use Tests\Support\BillingC3Fixtures;
use Tests\Support\BillingC3ProcessJob;
use Tests\Support\BillingC3Runtime;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationCommitProbe as CommitProbe;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Tests\Support\PaymentR2IntegrationRuntime as Integration;

$c3 = getenv('SILANSIA_BILLING_C3_MANIFEST') !== false;
if (! $c3) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
}
try {
    if ($c3) {
        require __DIR__.'/BillingC3Bootstrap.php';
        BillingC3Bootstrap::admit(dirname(__DIR__, 2), getenv('SILANSIA_BILLING_C3_MANIFEST'), false);
        require dirname(__DIR__, 2).'/vendor/autoload.php';
    }
    Identity::require(PHP_SAPI === 'cli-server', 'owned_web_sapi_required');
    if ($c3) {
        // Allow Laravel to distinguish its 2 MiB file limit from its 2112 KiB envelope limit.
        Identity::require(ini_get('upload_max_filesize') === '2112K', 'billing_c3_upload_ceiling_mismatch');
        $c3Guard = BillingC3Runtime::validate('browser');
    } else {
        Integration::validate(true);
    }
    $boot = $c3 ? static fn () => BillingC3Runtime::boot('browser') : Integration::boot(...);
    $origin = getenv('APP_URL') ?: '';
    $key = getenv($c3 ? 'SILANSIA_BILLING_C3_WEB_KEY' : 'SILANSIA_05E_WEB_KEY') ?: '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    Identity::require(preg_match('#\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z#', $origin) === 1
        && 'http://'.$host === $origin && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
        && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1, 'owned_web_origin_required');
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === ($c3 ? '/__silansia_c3_identity' : '/__silansia_05e_identity')) {
        $nonce = $_SERVER[$c3 ? 'HTTP_X_SILANSIA_C3_CHALLENGE' : 'HTTP_X_SILANSIA_05E_CHALLENGE'] ?? '';
        Identity::require($method === 'GET' && $uri === $path && preg_match('/\A[a-f0-9]{64}\z/', $nonce) === 1,
            'invalid_loopback_challenge');
        $app = $boot();
        Identity::require(! $app->runningInConsole() && $app->environment('testing') && ! $app->configurationIsCached(), 'native_web_middleware_required');
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode(['pid' => getmypid(), 'challenge' => hash_hmac('sha256', $nonce.'|'.getmypid().'|'.$origin, $key),
            'native_web' => true], JSON_THROW_ON_ERROR);

        return;
    }
    if (! hash_equals($key, $_SERVER[$c3 ? 'HTTP_X_SILANSIA_C3_WEB' : 'HTTP_X_SILANSIA_05E_WEB'] ?? '')) {
        http_response_code(404);

        return;
    }

    if ($c3 && ! BillingC3Runtime::allowedWeb($method, $uri, $origin, $_SERVER['HTTP_ORIGIN'] ?? null)) {
        http_response_code(404);

        return;
    }

    if (is_string($path) && str_starts_with($path, '/build/')) {
        Identity::require(in_array($method, ['GET', 'HEAD'], true)
            && preg_match('#\A/build/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:js|css|woff2?|svg|png|jpg|webp)\z#', $path) === 1, 'asset_path_invalid');
        $file = Runtime::path(Runtime::root().'/public'.$path);
        Identity::require(is_file($file) && ! is_link($file), 'owned_asset_missing');
        if ($c3) {
            $receipt = Identity::read(Runtime::root().'/assets-receipt.json');
            Identity::require($receipt['invocation'] === BillingC3Runtime::run() && isset($receipt['files'][$path])
                && hash_equals($receipt['files'][$path], hash_file('sha256', $file)), 'owned_asset_digest_mismatch');
        }
        // Deliver here so PHP never falls back to a different document root.
        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp',
        };
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if ($method === 'GET') {
            readfile($file);
        }

        return;
    }
    $app = $boot();
    Identity::require(! $app->runningInConsole(), 'native_web_middleware_required');
    $kernel = $app->make(Kernel::class);
    $request = Request::capture();
    $probe = $c3 ? null : CommitProbe::attach($request);
    $auditBefore = null;
    if ($c3) {
        BillingC3ProcessJob::assertLive();
        if (isset($_SERVER['HTTP_X_SILANSIA_C3_PROOF_AUDIT'])) {
            $uuid = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}';
            Identity::require($method === 'GET' && preg_match("~\A/billing/invoices/($uuid)/payments/($uuid)/proof\z~", $uri, $auditTarget) === 1
                && BillingC3Fixtures::target($auditTarget[1]) === 'primary', 'billing_c3_proof_audit_scope_invalid');
            $auditBefore = BillingC3Acceptance::protection($auditTarget[1], $auditTarget[2], $c3Guard);
        }
    }
    $response = $kernel->handle($request);
    if ($c3 && $response->isRedirection()) {
        $location = $response->headers->get('Location');
        Identity::require(is_string($location) && BillingC3Runtime::sameOriginLocation($location, $origin), 'owned_web_redirect_denied');
    }
    $probe?->beforeResponse($response);
    if ($auditBefore !== null) {
        $auditAfter = BillingC3Acceptance::protection($auditTarget[1], $auditTarget[2], $c3Guard);
        $grant = array_values(array_diff_key($auditAfter, $auditBefore));
        Identity::require($response->getStatusCode() === 200 && count($grant) === 1
            && count($auditAfter) === count($auditBefore) + 1 && $grant[0]->bound === true
            && $grant[0]->actor === $request->user()?->id, 'billing_c3_proof_audit_not_committed');
        $response->headers->set('X-Silansia-C3-Proof-Audit', 'committed-before-send');
    }
    $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' blob: data:; font-src 'self'; connect-src 'self' blob:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable) {
    if (! headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo '{"error":"owned_web_rejected"}';
    }
}
