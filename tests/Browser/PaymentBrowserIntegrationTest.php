<?php

namespace Tests\Browser;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\PaymentHttpTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('payment-ui')]
class PaymentBrowserIntegrationTest extends PaymentHttpTestCase
{
    public function test_real_chrome_payment_ui_laravel_postgres_and_private_storage(): void
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe(app(), DB::connection());
        $this->assertSame('1', getenv('SILANSIA_P94D_VERIFY'));
        $password = 'P94d!'.bin2hex(random_bytes(16));
        $sources = [];
        foreach (['happy', 'reject', 'lost', 'absent', 'reload', 'expired', 'session', 'zero'] as $name) {
            $s = $this->httpSource($name === 'zero' ? ['price' => '0.00'] : []);
            $s['elderly']->update(['name' => 'P94D '.$name.' '.substr((string) Str::uuid(), 0, 8)]);
            foreach (['admin', 'family', 'next'] as $role) {
                $s[$role]->update(['name' => 'Synthetic P94D '.$role, 'email' => 'p94d-'.Str::uuid().'@example.test', 'password' => $password]);
            }
            $sources[$name] = ['resident' => $s['elderly']->id, 'name' => $s['elderly']->name, 'invoice' => $s['issue']->invoiceId,
                'admin' => $s['admin']->only('id', 'email'), 'family' => $s['family']->only('id', 'email'), 'foreign' => $s['next']->only('id', 'email')];
        }
        $accounts = [];
        foreach (['caregiver' => ['perawat', true], 'inactive' => ['admin', false]] as $name => [$role,$active]) {
            $actor = $this->account($role, $active, ['name' => 'Synthetic P94D '.$name, 'email' => 'p94d-'.Str::uuid().'@example.test']);
            $actor->update(['password' => $password]);
            $accounts[$name] = $actor->only('id', 'email');
        }
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $proof = base64_encode(ob_get_clean());
        unset($image);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        $this->assertIsResource($socket);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->assertNotContains($port, [8000, 5187]);
        $origin = 'http://127.0.0.1:'.$port;
        $run = substr($guard->identity['database'], -16);
        $parent = realpath(dirname($guard->identity['cache']));
        $views = $parent.'/p94d-views';
        $uploads = $parent.'/p94d-uploads';
        $this->assertTrue(mkdir($views, 0700));
        $this->assertTrue(mkdir($uploads, 0700));
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => $origin,
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $guard->identity['cache'],
            'APP_ROUTES_CACHE' => $parent.'/p94d-routes.php', 'APP_EVENTS_CACHE' => $parent.'/p94d-events.php',
            'DB_CONNECTION' => 'silansia_testing', 'DB_DATABASE' => 'postgres', 'DB_URL' => '',
            'SILANSIA_P93D_VERIFY' => '1', 'SILANSIA_P94D_VERIFY' => '1', 'SILANSIA_TEST_IDENTITY' => json_encode($guard->identity, JSON_THROW_ON_ERROR),
            'SILANSIA_TEST_DB_DATABASE' => $guard->identity['database'], 'SILANSIA_TEST_DB_HOST' => $db['host'], 'SILANSIA_TEST_DB_PORT' => (string) $db['port'],
            'SILANSIA_TEST_DB_USERNAME' => $db['username'], 'SILANSIA_TEST_DB_PASSWORD' => $db['password'], 'SILANSIA_TEST_DB_SSLMODE' => $db['sslmode'] ?? 'prefer',
            'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'silansia_p94d_'.$run, 'SESSION_SECURE_COOKIE' => 'false',
            'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true', 'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-p94d-browser-only', 'INVOICE_ISSUANCE_ENABLED' => 'true',
            'PAYMENT_CORE_ENABLED' => 'true', 'VIEW_COMPILED_PATH' => $views, 'BCRYPT_ROUNDS' => '4', 'P94D_PHP_BINARY' => PHP_BINARY, 'P94D_EVIDENCE_RUN' => $run];
        $server = new Process([PHP_BINARY, '-d', 'upload_tmp_dir='.$uploads, '-S', '127.0.0.1:'.$port, '-t', 'public', 'tests/Support/p94d-server.php'], base_path(), $environment);
        $server->setTimeout(null);
        $server->start();
        try {
            $identity = null;
            for ($i = 0; $i < 60; $i++) {
                $raw = @file_get_contents($origin.'/__p94d/identity', false, stream_context_create(['http' => ['timeout' => 1]]));
                if ($raw !== false) {
                    $identity = json_decode($raw, true);
                    break;
                }
                if (! $server->isRunning()) {
                    break;
                } usleep(100000);
            }
            $this->assertIsArray($identity, 'Owned web server tidak siap.');
            $this->assertSame($guard->identity['database'], $identity['database'] ?? null);
            $this->assertSame('testing', $identity['environment'] ?? null);
            foreach (['ownership_verified', 'proof_root_owned', 'upload_temp_owned'] as $key) {
                $this->assertTrue($identity[$key] ?? false);
            }
            foreach (['config_cached', 'environment_file_exists', 'csrf_test_bypass'] as $key) {
                $this->assertFalse($identity[$key] ?? true);
            }
            file_put_contents(base_path('docs/report/SILANSIA_P9_4D_ISOLATION_'.$run.'.json'), json_encode($identity, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $browser = new Process(['node', 'tests/Browser/run-payment-laravel.mjs'], base_path(), $environment);
            $browser->setInput(json_encode(compact('origin', 'sources', 'accounts', 'password', 'proof'), JSON_THROW_ON_ERROR));
            $browser->setTimeout(420);
            $browser->run();
            $result = json_decode(trim($browser->getOutput()), true);
            $this->assertSame(0, $browser->getExitCode(), 'Real browser acceptance gagal; lihat evidence aman P9-4D.');
            $this->assertTrue($result['ok'] ?? false);
            $this->assertGreaterThanOrEqual(10, $result['passed'] ?? 0);
            $guard->assertSafe(app(), DB::connection());
            echo 'P94D_BROWSER '.json_encode(['passed' => $result['passed'], 'failed' => $result['failed'], 'database' => $guard->identity['database']]).PHP_EOL;
        } finally {
            $server->stop(2);
            foreach ([$views, $uploads] as $directory) {
                $this->assertStringStartsWith($parent.DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $directory));
                $this->assertFalse(is_link($directory));
                foreach (glob($directory.'/*') as $file) {
                    $this->assertFalse(is_link($file));
                    $this->assertTrue(is_file($file));
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }
}
