<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Services\Billing\BillingTime;
use App\Services\Billing\PaymentFailure;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;
use WeakMap;

final class ProofAuditBinding
{
    private const AUDIENCE = 'silansia.proof.audit.audience.v1';

    private const SESSION = 'silansia.proof.audit.session.v1';

    private const AUTHORIZATION = 'silansia.proof.audit.authorization.v1';

    /** @var WeakMap<Request, string>|null */
    private static ?WeakMap $nonces = null;

    /**
     * Prepare a binding for the current request. The verifier accepts only the
     * actual server-side context at the grant point, never a client digest.
     */
    public function prepare(string $kind, string $authority, string $actor, string $invoice, string $payment, string $subject, int $version): Closure
    {
        $request = request();
        self::$nonces ??= new WeakMap;
        $nonce = self::$nonces[$request] ??= bin2hex(random_bytes(32));
        $prepared = $this->digests($request, $kind, $authority, $nonce, $actor, $invoice, $payment, $subject, $version);

        return function (string $authority, string $actor, string $invoice, string $payment, string $subject, int $version) use ($request, $kind, $nonce, $prepared): array {
            $actual = $this->digests($request, $kind, $authority, $nonce, $actor, $invoice, $payment, $subject, $version);
            if (! hash_equals($prepared[0], $actual[0]) || ! hash_equals($prepared[1], $actual[1])) {
                throw new PaymentFailure('proof_use_unavailable');
            }

            return $actual;
        };
    }

    private function digests(Request $request, string $kind, string $authority, #[SensitiveParameter] string $nonce, string $actor, string $invoice, string $payment, string $subject, int $version): array
    {
        [$route, $method] = match ($kind) {
            'protected_get_granted' => ['payment.proof', 'GET'],
            'verify_use_granted' => ['payment.admin.verify', 'POST'],
            default => throw new PaymentFailure('proof_use_unavailable'),
        };
        foreach ([$actor, $invoice, $payment, $subject] as $uuid) {
            if (! BillingTime::uuid($uuid) || strtolower($uuid) !== $uuid) {
                throw new PaymentFailure('proof_use_unavailable');
            }
        }
        if ($version < 1 || ! preg_match('/\Aacademic-owned:[0-9a-f]{64}\z/', $authority)
            || ! app()->environment('testing') || request() !== $request || ! $request->routeIs($route)
            || $request->getRealMethod() !== $method || $request->getMethod() !== $method
            || $request->route('invoice') !== $invoice || $request->route('payment') !== $payment
            || ! $request->hasSession() || Auth::getDefaultDriver() !== 'web') {
            throw new PaymentFailure('proof_use_unavailable');
        }
        $guard = Auth::guard('web');
        $session = $request->session();
        if (! $guard instanceof SessionGuard || ! $session instanceof Store || $guard->getSession() !== $session
            || ! $session->isStarted() || ! $session->isValidId($session->getId())
            || $session->get($guard->getName()) !== $actor || $guard->id() !== $actor
            || $request->user()?->getAuthIdentifier() !== $actor) {
            throw new PaymentFailure('proof_use_unavailable');
        }

        $key = $this->activeKey();
        $audience = $this->mac($key, [self::AUDIENCE, 'testing', $authority, $route, $kind]);
        $sessionDigest = $this->mac($key, [self::SESSION, 'web', $session->getId(), $actor]);
        $authorization = $this->mac($key, [
            self::AUTHORIZATION, $audience, $sessionDigest, $nonce, $actor, $invoice, $payment, $subject, $version, $method, $kind,
        ]);

        return [$audience, $authorization];
    }

    private function activeKey(): string
    {
        $configured = config('app.key');
        $cipher = config('app.cipher');
        if (! is_string($configured) || $configured === '' || ! is_string($cipher) || ! function_exists('hash_hkdf')) {
            throw new PaymentFailure('proof_use_unavailable');
        }

        // Validate the current configuration and reject a cached encrypter that
        // still carries a different key after rotation. Use only its active bytes.
        if (str_starts_with($configured, 'base64:')) {
            $encoded = substr($configured, 7);
            $configured = base64_decode($encoded, true);
            if (! is_string($configured) || base64_encode($configured) !== $encoded) {
                throw new PaymentFailure('proof_use_unavailable');
            }
        }
        if (! Encrypter::supported($configured, $cipher)) {
            throw new PaymentFailure('proof_use_unavailable');
        }
        $encrypter = app('encrypter');
        if (! $encrypter instanceof Encrypter || ! hash_equals($configured, $encrypter->getKey())) {
            throw new PaymentFailure('proof_use_unavailable');
        }

        return $encrypter->getKey();
    }

    private function mac(#[SensitiveParameter] string $key, #[SensitiveParameter] array $fields): string
    {
        $subkey = hash_hkdf('sha256', $key, 32, $fields[0], 'silansia.proof.audit.kdf.v1');

        return hash_hmac('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $subkey);
    }
}
