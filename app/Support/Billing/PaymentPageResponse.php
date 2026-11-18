<?php

namespace App\Support\Billing;

use App\Services\Billing\PaymentFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PaymentPageResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('keluarga/tagihan', 'keluarga/tagihan/*', 'admin/pembayaran', 'admin/pembayaran/*');
    }

    public static function path(Request $request): string
    {
        return $request->getBaseUrl().($request->is('keluarga/*') ? '/keluarga/tagihan' : '/admin/pembayaran');
    }

    public static function error(Request $request, Response $original, \Throwable $error): Response
    {
        $status = $error instanceof PaymentFailure ? PaymentHttpResponse::error($error, $original)->getStatusCode() : $original->getStatusCode();
        $factory = clone Inertia::getFacadeRoot();
        $factory->flushShared();
        $factory->resolveUrlUsing(fn () => self::path($request));

        return PaymentHttpResponse::privacy($factory->render('Error', ['status' => $status, 'correlation_id' => (string) Str::uuid()])->toResponse($request)->setStatusCode($status));
    }
}
