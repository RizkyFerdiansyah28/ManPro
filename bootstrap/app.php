
<?php

use App\Exceptions\AiGatewayException;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureInvoiceResponsePrivacy;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRole;
use App\Support\Billing\InvoiceHttpResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureInvoiceResponsePrivacy::class,
            EnsureActiveAccount::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->appendToPriorityList(StartSession::class, EnsureInvoiceResponsePrivacy::class);
        $middleware->appendToPriorityList(EnsureInvoiceResponsePrivacy::class, EnsureActiveAccount::class);
        $middleware->alias(['role' => RequireRole::class]);
        $middleware->trimStrings(except: ['source_reference', 'confirmation_token']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->homeRoute()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error) {
            if (app()->bound('request') && InvoiceHttpResponse::matches(request())) {
                // Tidak meneruskan SQL/arguments/token ke default reporter pada boundary billing.
                Log::warning('Permintaan HTTP invoice gagal.', ['category' => 'invoice_http_failure']);

                return false;
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'source_reference', 'notes', 'confirmation_token']);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (InvoiceHttpResponse::matches($request)) {
                return $response->getStatusCode() >= 400
                    ? InvoiceHttpResponse::error($exception, $request, $response)
                    : InvoiceHttpResponse::privacy($response);
            }
            $status = $response->getStatusCode();
            if ($request->is('api/ai/*') && $status >= 400 && ! $exception instanceof AiGatewayException) {
                return response()->json([
                    'error' => ['code' => 'gateway_error', 'message' => 'Request gateway tidak dapat diproses.'],
                    'request_id' => (string) Str::uuid(),
                ], $status)->header('Cache-Control', 'no-store, private');
            }
            if ($exception instanceof AiGatewayException) {
                return $response;
            }
            if ($request->expectsJson() && $request->is('keluarga', 'keluarga/*')
                && in_array($status, [401, 403, 404, 405, 409, 419, 429, 500, 503], true)) {
                $message = match ($status) {
                    401 => 'Silakan masuk untuk melanjutkan.',
                    403 => 'Akses dibatasi.',
                    404 => 'Halaman tidak ditemukan.',
                    405 => 'Metode permintaan tidak diizinkan.',
                    419 => 'Sesi telah berakhir.',
                    429 => 'Terlalu banyak permintaan. Coba kembali nanti.',
                    default => 'Informasi belum dapat diproses. Silakan coba kembali.',
                };
                $error = response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store, private');
                foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                    if ($response->headers->has($header)) {
                        $error->headers->set($header, $response->headers->get($header));
                    }
                }

                return $error;
            }
            if (($request->expectsJson() || $request->is('api/*')) && in_array($status, [500, 503], true)) {
                return response()->json(['message' => 'Layanan sedang bermasalah. Silakan coba kembali nanti.'], $status)
                    ->header('Cache-Control', 'no-store, private');
            }
            if (! $request->expectsJson() && (in_array($status, [403, 404, 409, 419, 429, 500, 503], true)
                || ($status === 405 && $request->is('keluarga', 'keluarga/*')))) {
                $errorFactory = Inertia::getFacadeRoot();
                if ($request->is('monitoring/*', 'notifications', 'notifications/*', 'keluarga', 'keluarga/*', 'admin', 'perawat')) {
                    // Response error tidak perlu memantulkan query asing/privat.
                    // Clone mencegah resolver ini memengaruhi response/worker berikutnya.
                    $errorFactory = clone $errorFactory;
                    $errorFactory->resolveUrlUsing(fn (Request $request) => $request->getBaseUrl().$request->getPathInfo());
                }
                $error = $errorFactory->render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
                $error->headers->set('Cache-Control', 'no-store, private');
                foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                    if ($response->headers->has($header)) {
                        $error->headers->set($header, $response->headers->get($header));
                    }
                }

                return $error;
            }

            return $response;
        });
    })->create();
