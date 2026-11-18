<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Services\Billing\PaymentFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PaymentHttpResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('keluarga/billing', 'keluarga/billing/*', 'admin/billing/payments', 'admin/billing/payments/*',
            'admin/billing/invoices/*/payments', 'admin/billing/invoices/*/payments/*', 'billing/payment-commands/*', 'billing/invoices/*/payments/*');
    }

    public static function privacy(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public static function error(Throwable $error, Response $original): Response
    {
        $status = $error instanceof AuthenticationException ? 401 : max(400, $original->getStatusCode());
        $kind = $error instanceof PaymentFailure ? $error->kind : null;
        if ($kind !== null) {
            $status = match ($kind) {
                'invalid_command', 'invalid_rejection', 'invalid_proof', 'bank_confirmation_required', 'context_invalid' => 422,
                'target_not_found', 'invalid_payment_target', 'unmanaged_invoice' => 404,
                'unauthorized' => 403,
                'idempotency_conflict', 'invoice_not_eligible', 'pending_payment_exists', 'stale_payment_state', 'stale_owner_context', 'context_expired', 'outcome_unresolved' => 409,
                'integrity_violation' => 500,
                default => 503,
            };
        }
        $code = $kind ?? match ($status) {
            401 => 'authentication_required', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
            406 => 'json_required', 413 => 'request_too_large', 415 => 'unsupported_media', 419 => 'session_expired',
            422 => 'invalid_input', 429 => 'rate_limited', default => 'request_failed',
        };
        // Never serialize exception messages, SQL, paths, validator input or request bodies.
        $response = response()->json(['error' => ['code' => $code, 'message' => 'Permintaan pembayaran belum dapat diproses.',
            'retry_mode' => 'reconcile_original_intent'], 'financial_outcome' => 'undisclosed', 'correlation_id' => (string) Str::uuid()], $status);
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            if ($original->headers->has($header)) {
                $response->headers->set($header, $original->headers->get($header));
            }
        }

        return self::privacy($response);
    }
}
