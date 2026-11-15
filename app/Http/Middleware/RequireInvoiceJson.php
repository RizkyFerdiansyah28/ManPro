<?php

namespace App\Http\Middleware;

use App\Exceptions\InvoiceHttpException;
use Closure;
use Illuminate\Http\Request;

class RequireInvoiceJson
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->expectsJson() && ! $request->header('X-Inertia'), 406);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 8192, 413);
        try {
            $value = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvoiceHttpException('invalid_input');
        }
        if (! is_object($value) || $request->query->count() !== 0) {
            throw new InvoiceHttpException('invalid_input');
        }

        return $next($request);
    }
}
