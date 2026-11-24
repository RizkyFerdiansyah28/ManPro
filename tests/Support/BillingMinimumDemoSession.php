<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Throwable;

/** One cookie jar for a single actor in the in-process Laravel HTTP kernel. */
final class BillingMinimumDemoSession
{
    private array $cookies = [];

    private string $sessionId;

    public function __construct(private readonly Application $app) {}

    public function login(User $user, #[\SensitiveParameter] string $password, bool $proveCsrf = false): void
    {
        $this->request('GET', '/', 302);
        Identity::require(isset($this->cookies['XSRF-TOKEN']), 'billing_demo_assertion_failed');
        $credentials = ['email' => $user->email, 'password' => $password];
        if ($proveCsrf) {
            // Rejection is on login, so it never dispatches a second financial command.
            $this->request('POST', '/login', 419, $credentials, csrf: false);
        }
        $this->request('POST', '/login', 302, $credentials);
        Identity::require($this->app['auth']->guard('web')->id() === $user->id, 'billing_demo_assertion_failed');
        $this->sessionId = $this->app['session']->driver()->getId();
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function json(string $method, string $path, int $expected, #[\SensitiveParameter] array $data = [], ?UploadedFile $proof = null): array
    {
        $response = $this->request($method, $path, $expected, $data, $proof);
        $body = json_decode($response->getContent(), true);
        Identity::require(is_array($body), 'billing_demo_assertion_failed');

        return $body;
    }

    public function request(string $method, string $path, int $expected, #[\SensitiveParameter] array $data = [],
        ?UploadedFile $proof = null, bool $csrf = true): Response
    {
        // The in-process Store object is reused across requests. StartSession reloads
        // attributes from the supplied cookie after this reset.
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        $json = $method === 'POST' && $path !== '/login' && $proof === null;
        $content = $json ? json_encode($data, JSON_THROW_ON_ERROR) : null;
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        if ($method === 'POST' && $csrf) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'] ?? '';
        }
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $server['CONTENT_LENGTH'] = strlen($content);
        } elseif ($proof !== null) {
            // Simulate the PHP SAPI's parsed multipart fields and file bag.
            $server['CONTENT_TYPE'] = 'multipart/form-data; boundary=silansia-minimum-demo';
        }
        $request = Request::create('http://127.0.0.1'.$path, $method, $json ? [] : $data, $this->cookies,
            $proof === null ? [] : ['proof' => $proof], $server, $content);
        $kernel = $this->app->make(Kernel::class);
        $financialWrite = $method === 'POST' && ! in_array($path, ['/login', '/admin/billing/invoices/preview'], true);
        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } catch (Throwable $error) {
            if ($financialWrite) {
                throw new LogicException('billing_demo_outcome_unconfirmed');
            }
            throw $error;
        }
        if ($financialWrite && $response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            if ($response->getStatusCode() >= 500 || in_array($body['error']['code'] ?? null, [
                'outcome_unresolved', 'outcome_unknown', 'unknown_commit_outcome',
            ], true)) {
                throw new LogicException('billing_demo_outcome_unconfirmed');
            }
        }
        Identity::require($response->getStatusCode() === $expected, 'billing_demo_assertion_failed');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }
}
