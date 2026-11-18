<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Services\Billing\PaymentFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class PaymentCommandContext
{
    public function issue(Request $request, string $purpose, array $facts = []): string
    {
        $issuedAt = time();

        return Crypt::encryptString(json_encode(['v' => 1, 'purpose' => $purpose, 'actor' => $request->user()->id,
            'session' => $this->session($request), 'audience' => $this->audience(), 'issued_at' => $issuedAt, 'expires_at' => $issuedAt + 300,
            'request_id' => (string) Str::uuid(), ...$facts], JSON_THROW_ON_ERROR));
    }

    public function read(Request $request, string $token, string $purpose, bool $expired = false): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['v'] ?? null) !== 1 || ($data['purpose'] ?? null) !== $purpose
                || ($data['actor'] ?? null) !== $request->user()->id || ($data['session'] ?? null) !== $this->session($request)
                || ($data['audience'] ?? null) !== $this->audience() || ! is_int($data['expires_at'] ?? null)
                || ! is_int($data['issued_at'] ?? null) || $data['issued_at'] > time()
                || $data['expires_at'] - $data['issued_at'] !== 300 || ! Str::isUuid($data['request_id'] ?? '')) {
                throw new PaymentFailure('context_invalid');
            }
        } catch (Throwable) {
            throw new PaymentFailure('context_invalid');
        }
        if (! $expired && $data['expires_at'] < time()) {
            throw new PaymentFailure('context_expired');
        }

        return $data;
    }

    private function session(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), 'silansia.payment.session.v1|'.config('app.key'));
    }

    private function audience(): string
    {
        return hash('sha256', 'silansia.payment.http.v1|'.(getenv('SILANSIA_TEST_IDENTITY') ?: 'operational-disabled'));
    }
}
