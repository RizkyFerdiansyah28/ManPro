<?php

namespace App\Support\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Services\Billing\InvoiceIssuanceFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InvoiceHttpResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('admin/billing/invoices', 'admin/billing/invoices/*');
    }

    public static function privacy(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public static function safePath(Request $request): string
    {
        $path = '/'.$request->path();
        $base = '/admin/billing/invoices';
        if (! in_array($path, [$base, $base.'/create', $base.'/resident-options', $base.'/preview', $base.'/reconcile'], true)
            && ! preg_match('~\A'.preg_quote($base, '~').'/[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}\z~', $path)) {
            $path = $base;
        }

        return $request->getBaseUrl().$path;
    }

    public static function error(Throwable $exception, Request $request, Response $original): Response
    {
        $kind = $exception instanceof InvoiceHttpException || $exception instanceof InvoiceIssuanceFailure ? $exception->kind : null;
        [$status, $code, $mode] = match ($kind) {
            'invalid_input', 'invalid_command' => [422, 'invalid_input', 'correct_input'],
            'confirmation_invalid' => [422, 'confirmation_invalid', 'review'],
            'confirmation_expired' => [409, 'confirmation_expired', 'reconcile_same_intent'],
            'outcome_unresolved' => [409, 'outcome_unresolved', 'reconcile_same_intent'],
            'stale_preview' => [409, 'preview_stale', 'review'],
            'existing_root_conflict' => [409, 'invoice_exists', 'review'],
            'idempotency_conflict' => [409, 'request_conflict', 'review'],
            'held' => [409, 'billing_review_required', 'review'],
            'no_service' => [422, 'no_chargeable_service', 'review'],
            'rejected' => [422, 'billing_rejected', 'correct_input'],
            'unauthorized' => [403, 'forbidden', 'authenticate'],
            'lock_timeout', 'statement_timeout' => [503, 'billing_busy', 'same_intent'],
            'transient_retry_exhausted' => [503, 'retry_exhausted', 'same_intent'],
            'unknown_commit_outcome', 'infrastructure_failure' => [503, 'outcome_unknown', 'reconcile_same_intent'],
            'integrity_violation', 'broken_graph' => [500, 'billing_integrity_error', 'review'],
            null => [self::status($exception, $original), 'request_failed', 'review'],
            default => [503, 'billing_unavailable', 'review'],
        };
        if ($kind === null) {
            $code = match ($status) {
                401 => 'authentication_required', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
                406 => 'json_required', 413 => 'request_too_large', 415 => 'json_body_required', 419 => 'session_expired',
                422 => 'invalid_input', 429 => 'rate_limited', default => 'request_failed',
            };
        }
        $message = match ($code) {
            'authentication_required', 'session_expired' => 'Silakan masuk kembali untuk melanjutkan.',
            'forbidden' => 'Akses invoice dibatasi.', 'not_found' => 'Invoice atau lansia tidak ditemukan.',
            'invalid_input' => 'Periksa isian permintaan invoice.', 'confirmation_invalid' => 'Konfirmasi invoice tidak valid.',
            'confirmation_expired' => 'Konfirmasi telah berakhir. Periksa hasil intent yang sama bila sebelumnya dikirim.',
            'preview_stale' => 'Sumber invoice berubah. Tinjau kembali sebelum melanjutkan.',
            'invoice_exists' => 'Invoice untuk lansia dan bulan tersebut sudah tersedia.',
            'request_conflict' => 'ID permintaan telah digunakan untuk intent berbeda.',
            'billing_review_required' => 'Data layanan atau penanggung finansial memerlukan review Admin.',
            'no_chargeable_service' => 'Tidak ada layanan yang dapat ditagih pada bulan tersebut.',
            'billing_rejected' => 'Periode atau konteks invoice belum dapat diterbitkan.',
            'outcome_unknown', 'outcome_unresolved' => 'Hasil penerbitan belum dapat dipastikan. Pertahankan ID dan konfirmasi yang sama.',
            'billing_busy', 'retry_exhausted' => 'Layanan invoice sedang sibuk. Jangan mengganti intent permintaan.',
            'rate_limited' => 'Terlalu banyak permintaan invoice. Coba kembali nanti.',
            'method_not_allowed', 'json_required', 'json_body_required' => 'Gunakan metode dan format permintaan invoice yang sesuai.',
            'request_too_large' => 'Permintaan invoice terlalu besar.',
            default => 'Layanan invoice belum dapat memproses permintaan. Hubungi administrator bila berlanjut.',
        };
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            $body = ['error' => ['code' => $code, 'message' => $message, 'retry_mode' => $mode],
                // Penolakan saat ini tidak membuktikan outcome upaya terdahulu, khususnya setelah auth hilang.
                'financial_outcome' => in_array($code, ['outcome_unknown', 'outcome_unresolved'], true) ? 'unknown' : 'undisclosed',
                'correlation_id' => (string) Str::uuid()];
            if ($exception instanceof InvoiceHttpException && $exception->fields !== []) {
                $body['errors'] = $exception->fields;
            }
            $response = response()->json($body, $status);
        } else {
            $factory = clone Inertia::getFacadeRoot();
            $factory->flushShared();
            $factory->resolveUrlUsing(fn (Request $r) => self::safePath($r));
            $response = $factory->render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
        }
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            if ($original->headers->has($header)) {
                $response->headers->set($header, $original->headers->get($header));
            }
        }

        return self::privacy($response);
    }

    private static function status(Throwable $exception, Response $response): int
    {
        if ($exception instanceof AuthenticationException) {
            return 401;
        }
        if ($exception instanceof AuthorizationException) {
            return 403;
        }

        return $response->getStatusCode() >= 400 ? $response->getStatusCode() : 500;
    }
}
