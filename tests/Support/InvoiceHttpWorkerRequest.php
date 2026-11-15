<?php

namespace Tests\Support;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class InvoiceHttpWorkerRequest
{
    public static function prepare(array $data): array
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        config(['session.driver' => 'database', 'session.connection' => 'silansia_testing']);
        app('session')->forgetDrivers();
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $session = app('session')->driver();
        $session->start();
        $csrf = $session->token();
        $session->put(Auth::guard('web')->getName(), $data['actor']);
        $session->save(); // Akun/sesi fixture hanya pada owned DB; bukan bypass middleware HTTP.
        $cookie = config('session.cookie');
        $encrypter = app('encrypter');
        $value = $encrypter->encrypt(CookieValuePrefix::create($cookie, $encrypter->getKey()).$session->getId(), EncryptCookies::serialized($cookie));
        Auth::forgetGuards(); // HTTP request must reload the user through its real session.

        return ['cookie' => $cookie, 'value' => $value, 'csrf' => $csrf];
    }

    public static function handle(array $data, #[\SensitiveParameter] array $body, #[\SensitiveParameter] array $session): array
    {
        $request = Request::create($data['path'], $data['method'] ?? 'POST', [], [$session['cookie'] => $session['value']], [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $session['csrf'],
                'HTTP_HOST' => 'localhost', ...(($data['method'] ?? 'POST') === 'GET' ? [
                    'HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '',
                ] : [])],
            json_encode($body, JSON_THROW_ON_ERROR));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $json = json_decode($response->getContent(), true);
        $kernel->terminate($request, $response);
        if (! is_array($json)) {
            throw new \LogicException('http_worker_expected_json');
        }

        return ['ok' => $response->getStatusCode() < 400, 'http_status' => $response->getStatusCode(),
            'kind' => $json['error']['code'] ?? null, 'decision' => $json['decision'] ?? null,
            'invoice' => $json['invoice_id'] ?? null, 'revision' => $json['revision_id'] ?? null,
            'replayed' => $json['replayed'] ?? false, 'amount' => $json['amount'] ?? null,
            'no_store' => str_contains($response->headers->get('Cache-Control', ''), 'no-store'),
            'csrf_enforced' => true, 'session_driver' => 'database',
            'page' => $json['component'] ?? null, 'resident_name_current' => $json['props']['invoice']['resident']['name_current'] ?? null,
            'read_total' => $json['props']['invoices']['total'] ?? null, 'read_count' => count($json['props']['invoices']['data'] ?? [])];
    }
}
