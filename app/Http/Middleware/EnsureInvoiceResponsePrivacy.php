<?php

namespace App\Http\Middleware;

use App\Exceptions\InvoiceHttpException;
use App\Support\Billing\InvoiceHttpResponse;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInvoiceResponsePrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InvoiceHttpResponse::matches($request)) {
            $allowed = match (true) {
                $request->isMethod('POST') => [],
                $request->routeIs('*.index') => ['elderly_id', 'billing_month', 'status', 'page', 'per_page', 'sort'],
                $request->routeIs('*.create') => ['elderly_id', 'billing_month'],
                $request->routeIs('*.resident-options') => ['search', 'page', 'per_page'], default => [],
            };
            if (array_diff(array_keys($request->query->all()), $allowed) !== []) {
                // Prevent framework intended/previous URL storage from retaining a query token.
                $request->query->replace([]);
                $request->server->set('QUERY_STRING', '');
                throw new InvoiceHttpException('invalid_input');
            }
        }
        $response = $next($request);
        if (InvoiceHttpResponse::matches($request)) {
            if ($response->getStatusCode() >= 300) {
                $request->query->replace([]);
                $request->server->set('QUERY_STRING', '');
                if ($response->isRedirection() && $request->hasSession()) {
                    $request->session()->forget('url.intended');
                }
            }
            if ($response->headers->has('X-Inertia-Location')) {
                $response->headers->set('X-Inertia-Location', InvoiceHttpResponse::safePath($request));
            }
            // Semua early returns juga privat; tidak mengubah revoke/session behavior middleware existing.
            if (($request->isMethod('POST') || $request->expectsJson()) && $response->isRedirection()) {
                return InvoiceHttpResponse::error(new AuthenticationException,
                    $request, response('', 401));
            }

            return InvoiceHttpResponse::privacy($response);
        }

        return $response;
    }
}
