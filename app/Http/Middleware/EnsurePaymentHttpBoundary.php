<?php

namespace App\Http\Middleware;

use App\Services\Billing\PaymentProofEnvironment;
use App\Support\Billing\PaymentHttpResponse;
use App\Support\Billing\PaymentPageResponse;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePaymentHttpBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PaymentPageResponse::matches($request)) {
            if ($request->query->all() !== []) {
                $request->query->replace([]);
                $request->server->set('QUERY_STRING', '');
                abort(422);
            }
            foreach (['invoice', 'payment'] as $parameter) {
                if (is_string($request->route($parameter))) {
                    $request->route()->setParameter($parameter, strtolower($request->route($parameter)));
                }
            }
            app(PaymentProofEnvironment::class)->root();
            $response = $next($request);
            if ($response->isRedirection() && $request->hasSession()) {
                $request->session()->forget('url.intended');
            }

            return PaymentHttpResponse::privacy($response);
        }
        if (! PaymentHttpResponse::matches($request)) {
            return $next($request);
        }
        foreach (['invoice', 'payment'] as $parameter) {
            $value = $request->route($parameter);
            if (is_string($value)) {
                $request->route()->setParameter($parameter, strtolower($value));
            }
        }
        $allowed = match (true) {
            $request->isMethod('GET') && $request->routeIs('payment.family.index', 'payment.admin.index') => ['page', 'status'],
            $request->routeIs('payment.admin.context') => ['operation'], default => [],
        };
        if (array_diff(array_keys($request->query->all()), $allowed)) {
            $request->query->replace([]);
            $request->server->set('QUERY_STRING', '');
            abort(422);
        }
        // Reject before authentication redirects can retain request URLs/tokens.
        $jsonAccept = AcceptHeader::fromString($request->header('Accept', ''))->get('application/json');
        if (! $jsonAccept || $jsonAccept->getQuality() <= 0 || $request->headers->has('X-Inertia')) {
            abort(406);
        }
        app(PaymentProofEnvironment::class)->root();
        if (! $request->isMethodSafe()) {
            $upload = $request->routeIs('payment.family.submit');
            if ((int) $request->server('CONTENT_LENGTH', 0) > ($upload ? 2162688 : 16384)) {
                abort(413);
            }
            if ($upload && $request->file('proof') && ! $request->file('proof') instanceof UploadedFile) {
                abort(422);
            }
            if ($upload && $request->file('proof') && $request->file('proof')->getSize() > 2097152) {
                abort(413);
            }
            if ($upload ? ! str_starts_with(strtolower($request->header('Content-Type', '')), 'multipart/form-data;') : strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                abort(415);
            }
            if (! $upload && (! is_array(json_decode($request->getContent(), true)) || ! str_starts_with(ltrim($request->getContent()), '{'))) {
                abort(422);
            }
        }
        $response = $next($request);
        if ($response->isRedirection()) {
            $request->session()->forget('url.intended');

            return PaymentHttpResponse::error(new AuthenticationException, response('', 401));
        }

        return PaymentHttpResponse::privacy($response);
    }
}
