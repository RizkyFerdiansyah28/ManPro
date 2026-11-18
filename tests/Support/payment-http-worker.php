<?php

use App\Models\Elderly;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Services\Billing\PaymentCanonical;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentReadiness;
use App\Services\Billing\PaymentService;
use App\Services\ResidentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrameworkRuntimeIsolation;
use Tests\Support\PostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$emit = null;
try {
    $guard = PostgresTestGuard::fromEnvironment();
    $guard->assertCacheIsolated();
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'silansia_testing') {
        throw new LogicException('environment_mismatch');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    FrameworkRuntimeIsolation::attach($app);
    $app->make(Kernel::class)->bootstrap();
    $guard->assertSafe($app, DB::connection());
    config(['payment_core.enabled' => true, 'payment_http.enabled' => true, 'payment_http.synthetic_validation' => true]);
    $data = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Ap92d_[a-f0-9]{16}\z/', $data['case'] ?? '')) {
        throw new LogicException('case_invalid');
    }
    $app->instance(PreventRequestForgery::class, new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    if (($data['fault'] ?? '') === 'ack_loss') {
        $app->bind(PaymentService::class, fn ($app) => new class($app->make(PaymentReadiness::class), $app->make(PaymentProofStore::class), $app->make(PaymentCanonical::class), $app->make(PaymentPolicy::class)) extends PaymentService
        {
            protected function commit(): void
            {
                parent::commit();
                throw new RuntimeException('synthetic acknowledgment loss');
            }
        });
    }
    $cookie = null;
    if (($data['operation'] ?? '') === 'http') {
        config(['session.driver' => 'database', 'session.connection' => 'silansia_testing']);
        app('session')->forgetDrivers();
        $session = app('session')->driver();
        $session->setId($data['session_id']);
        $session->start();
        $session->put('_token', 'synthetic-p94c-csrf');
        $session->put(Auth::guard('web')->getName(), $data['actor']);
        $session->save();
        $cookieName = config('session.cookie');
        $encrypter = app('encrypter');
        $cookie = [$cookieName => $encrypter->encrypt(CookieValuePrefix::create($cookieName, $encrypter->getKey()).$session->getId(), EncryptCookies::serialized($cookieName))];
        Auth::forgetGuards();
    }
    $inside = false;
    $counts = [];
    $emit = function (string $point, array $extra = []) use ($data) {
        echo json_encode(['case' => $data['case'], 'point' => $point, 'os_pid' => getmypid(), 'backend_pid' => DB::scalar('SELECT pg_backend_pid()'), ...$extra], JSON_THROW_ON_ERROR).PHP_EOL;
        fflush(STDOUT);
    };
    $point = function (string $name) use (&$inside, &$counts, $data, $emit) {
        $inside = true;
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $emit($name, ['occurrence' => $counts[$name]]);
        if (in_array($name.':'.$counts[$name], $data['pause'] ?? [], true)) {
            if (trim(fgets(STDIN) ?: '') !== 'go') {
                throw new LogicException('barrier_invalid');
            }
            $emit('released', ['barrier' => $name]);
        }
        $inside = false;
    };
    app('events')->listen(TransactionBeginning::class, fn () => $point('begin'));
    app('events')->listen(TransactionCommitting::class, fn () => $point('before_commit'));
    DB::listen(function ($query) use (&$inside, $point) {
        if ($inside) {
            return;
        }
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') && str_contains($sql, '"users"')) {
            $point('lock_users');
        }
    });
    $emit('ready', ['database' => $guard->identity['database'], 'oid' => $guard->identity['oid']]);
    if (trim(fgets(STDIN) ?: '') !== 'go') {
        throw new LogicException('release_required');
    }
    $emit('released');
    if ($data['operation'] === 'transfer') {
        app(ResidentService::class)->reassignFamily(User::findOrFail($data['actor']), Elderly::findOrFail($data['resident']),
            ['family_id' => $data['new_family'], 'current_family_id' => $data['old_family'], 'confirm_reassignment' => true]);
        $emit('outcome', ['kind' => 'success']);
    } else {
        $file = [];
        if (isset($data['upload'])) {
            $root = app(PaymentProofEnvironment::class)->root();
            $real = realpath($data['upload']);
            if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/')) {
                throw new LogicException('upload_isolation');
            }
            $file['proof'] = new UploadedFile($real, 'synthetic.png', null, null, true);
        }
        $multipart = $file !== [];
        $body = $data['body'];
        $request = Request::create($data['path'], 'POST', $multipart ? $body : [], $cookie, $file,
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $multipart ? 'multipart/form-data; boundary=synthetic-p94c' : 'application/json',
                'HTTP_HOST' => 'localhost', 'HTTP_X_CSRF_TOKEN' => 'synthetic-p94c-csrf'], $multipart ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
        $emit('outcome', ['kind' => $response->getStatusCode() < 400 ? 'success' : ($json['error']['code'] ?? 'error'), 'status' => $response->getStatusCode(),
            'payment' => $json['payment_id'] ?? null, 'fact' => $json['command_fact'] ?? null, 'replayed' => $json['replayed'] ?? false,
            'csrf_enforced' => true, 'no_store' => str_contains($response->headers->get('Cache-Control', ''), 'no-store')]);
    }
} catch (Throwable) {
    if ($emit) {
        $emit('outcome', ['kind' => 'infrastructure']);
    } else {
        echo '{"point":"outcome","kind":"infrastructure"}';
    }
    exit(1);
}
