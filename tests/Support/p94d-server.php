<?php

use App\Services\Billing\PaymentProofEnvironment;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\P94dWebApplication;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = P94dWebApplication::boot();
    $guard = PostgresTestGuard::fromEnvironment();
    $parent = realpath(dirname($guard->identity['cache']));
    $upload = realpath(ini_get('upload_tmp_dir'));
    if ($upload !== $parent.DIRECTORY_SEPARATOR.'p94d-uploads' || is_link($upload)) {
        throw new LogicException('upload_isolation_required');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/__p94d/identity') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['environment' => $app->environment(), 'connection' => config('database.default'),
            'database' => DB::scalar('SELECT current_database()'), 'ownership_verified' => true,
            'config_cached' => $app->configurationIsCached(), 'environment_file_exists' => file_exists($app->environmentFilePath()),
            'csrf_test_bypass' => $app->runningInConsole() && $app->runningUnitTests(),
            'proof_root_owned' => str_starts_with(app(PaymentProofEnvironment::class)->root(), $parent.DIRECTORY_SEPARATOR),
            'upload_temp_owned' => true, 'admission' => 'synthetic_owned_testing_only'], JSON_THROW_ON_ERROR);

        return;
    }
    if (is_string($path) && str_starts_with($path, '/build/')) {
        $file = realpath($app->publicPath().$path);
        $build = realpath($app->publicPath('build'));
        if ($file && $build && str_starts_with($file, $build.DIRECTORY_SEPARATOR) && is_file($file)) {
            return false;
        }
    }
    $guard->assertSafe($app, DB::connection());
    $kernel = $app->make(Kernel::class);
    $request = Request::capture();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"p94d_isolation_or_server_failure"}';
}
