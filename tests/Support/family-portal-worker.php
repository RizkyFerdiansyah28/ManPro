<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing'
        || getenv('SESSION_DRIVER') !== 'array' || getenv('CACHE_STORE') !== 'array') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    $data = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('~\A/keluarga(?:/residents(?:/[a-f0-9-]{36})?)?\z~', $data['path'] ?? '')) {
        throw new LogicException('path_invalid');
    }
    $actor = User::findOrFail($data['actor_id']);
    Auth::setUser($actor);
    $pid = DB::scalar('SELECT pg_backend_pid()');
    DB::listen(function ($event) use ($pid) {
        if (str_contains($event->sql, 'owned AS MATERIALIZED')) {
            echo json_encode(['snapshot' => true, 'pid' => $pid]).PHP_EOL;
            fflush(STDOUT);
            if (trim(fgets(STDIN)) !== 'go') {
                throw new LogicException('checkpoint_invalid');
            }
        }
    });
    $guard->assertSafe($app, DB::connection());
    $request = Request::create($data['path'], 'GET', server: ['HTTP_X_INERTIA' => 'true',
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $request->headers->set('X-Inertia-Version', app(HandleInertiaRequests::class)->version($request));
    $response = $app->make(HttpKernel::class)->handle($request);
    echo json_encode(['ok' => true, 'status' => $response->getStatusCode(),
        'page' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'kind' => 'infrastructure', 'exception' => $e::class]).PHP_EOL;
    exit(1);
}
